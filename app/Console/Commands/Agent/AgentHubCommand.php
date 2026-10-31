<?php

namespace App\Console\Commands\Agent;

use App\Actions\Agent\DispatchAgentJobs;
use App\Actions\Agent\TestAgentSnmp;
use App\Enums\AgentStatus;
use App\Jobs\IngestAgentPayloadJob;
use App\Models\Agent;
use App\Models\Subnet;
use App\Services\Agent\AgentReleases;
use App\Support\EngineLog;
use Clue\React\Redis\Factory as RedisFactory;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Message as Psr7Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Psr\Http\Message\RequestInterface;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\Message;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Stream\ReadableResourceStream;
use React\Socket\SocketServer;

/**
 * The remote-agent WebSocket hub. Agents dial in over TLS (nginx terminates + proxies /agent
 * here), authenticate with a Bearer token, and stay connected; the hub marks them online,
 * folds their results into the pipeline ({@see \App\Jobs\IngestAgentPayloadJob}), and forwards poll/scan
 * jobs the loop publishes over Redis ({@see DispatchAgentJobs}).
 *
 * A long-running ReactPHP process (like Reverb/Horizon), built on the same react/socket +
 * ratchet/rfc6455 stack Reverb uses. Run it under a supervisor (systemd unit shipped in the
 * package). nginx must proxy the `/agent` location to $host:$port.
 */
class AgentHubCommand extends Command
{
    protected $signature = 'mymate:agent-hub {--host=127.0.0.1} {--port=9091}';

    protected $description = 'Run the remote-agent WebSocket hub (agents connect here).';

    // Server-side keepalive: ping every connected agent this often. Must stay well under the
    // reverse-proxy read timeout on /agent (nginx: 3600s) AND any stateful firewall's idle
    // timeout on the path, so a quiet link (agents are idle between polls) is never dropped and
    // a dead peer is spotted within one interval.
    private const KEEPALIVE_SECONDS = 30;

    /** @var array<int, ConnectionInterface> agentId => live connection */
    private array $conns = [];

    private ServerNegotiator $negotiator;

    public function handle(): int
    {
        $this->negotiator = new ServerNegotiator(new RequestVerifier, new HttpFactory);
        $loop = Loop::get();

        $host = (string) $this->option('host');
        $port = (int) $this->option('port');

        // Any agent left 'online' from a previous run isn't connected to THIS process - reset.
        Agent::where('status', AgentStatus::Online)->update(['status' => AgentStatus::Offline]);

        $socket = new SocketServer("$host:$port", [], $loop);
        $socket->on('connection', fn (ConnectionInterface $c) => $this->onConnection($c));

        $this->subscribeRedis($loop);

        // Keepalive pings keep idle agent links warm through nginx / firewalls and surface dead
        // peers promptly (a failed write closes the connection -> onClose marks it offline).
        $loop->addPeriodicTimer(self::KEEPALIVE_SECONDS, fn () => $this->pingAll());

        $this->info("agent hub listening on ws://$host:$port  (proxy /agent -> here)");
        EngineLog::info('agent hub started', ['host' => $host, 'port' => $port]);
        $loop->run();

        return self::SUCCESS;
    }

    private function onConnection(ConnectionInterface $conn): void
    {
        // Per-connection state: pre-handshake we buffer raw HTTP; after, a MessageBuffer deframes.
        $state = (object) ['handshaked' => false, 'raw' => '', 'agentId' => null, 'mb' => null, 'conn' => null];

        $conn->on('data', fn ($data) => $this->onData($conn, $state, $data));
        $conn->on('close', fn () => $this->onClose($state));
        $conn->on('error', fn () => $conn->close());
    }

    private function onData(ConnectionInterface $conn, object $state, string $data): void
    {
        if ($state->handshaked) {
            $state->mb->onData($data);

            return;
        }

        // Accumulate until we have the full HTTP upgrade request.
        $state->raw .= $data;
        $end = strpos($state->raw, "\r\n\r\n");
        if ($end === false) {
            if (strlen($state->raw) > 16384) {
                $this->reject($conn, 413, 'Payload Too Large');
            }

            return;
        }

        try {
            $request = Psr7Message::parseRequest($state->raw);
        } catch (\Throwable) {
            $this->reject($conn, 400, 'Bad Request');

            return;
        }

        $agent = Agent::fromToken($this->bearer($request));
        if ($agent === null) {
            $this->reject($conn, 401, 'Unauthorized');

            return;
        }

        // A plain GET for an upgrade binary (remote upgrades), on the same /agent path and token
        // as the WebSocket, so it works through whatever proxy the agent already reaches us by.
        if ($request->getMethod() === 'GET' && preg_match('#/agent/binary/([^/]+)/([a-z0-9]+)_([a-z0-9]+)$#', $request->getUri()->getPath(), $m)) {
            $this->serveBinary($conn, $m[1], $m[2].'/'.$m[3]);

            return;
        }

        try {
            $response = $this->negotiator->handshake($request);
        } catch (\Throwable) {
            $this->reject($conn, 400, 'Bad Request');

            return;
        }
        $conn->write(Psr7Message::toString($response));

        $state->handshaked = true;
        $state->agentId = $agent->id;
        $state->mb = new MessageBuffer(
            new CloseFrameChecker,
            fn (Message $m) => $this->onMessage($agent->id, $m->getPayload()),
            fn (Frame $f) => $this->onControl($conn, $agent->id, $f),
            true, // client -> server frames are masked
        );

        // The same token connecting again (an agent restarted before its old socket timed out, or
        // two copies running): the newest wins and the old socket is closed. onClose only acts
        // for the connection that's current, so the old one closing can't mark the agent offline.
        $previous = $this->conns[$agent->id] ?? null;
        if ($previous !== null && $previous !== $conn) {
            EngineLog::warning('agent reconnected, closing its previous connection', ['agent' => $agent->name, 'id' => $agent->id]);
            // Say why first: an agent that understands it backs off for a while instead of
            // reconnecting straight away, so two copies sharing a token don't flap every second.
            $previous->end((new Frame((string) json_encode(['type' => 'bye', 'payload' => ['reason' => 'replaced']]), true, Frame::OP_TEXT))->getContents());
        }
        $state->conn = $conn;
        $this->conns[$agent->id] = $conn;
        $agent->forceFill(['status' => AgentStatus::Online, 'last_seen_at' => now()])->save();
        EngineLog::info('agent connected', ['agent' => $agent->name, 'id' => $agent->id]);

        // Bytes after the header terminator (if the client pipelined a frame) are WS data.
        $leftover = substr($state->raw, $end + 4);
        if ($leftover !== '') {
            $state->mb->onData($leftover);
        }
    }

    private function onMessage(int $agentId, string $json): void
    {
        $msg = json_decode($json, true);
        if (! is_array($msg)) {
            return;
        }

        switch ($msg['type'] ?? '') {
            case 'hello':
                Agent::where('id', $agentId)->update([
                    'version' => $msg['version'] ?? null,
                    'platform' => $msg['platform'] ?? null,
                    // Optional extras it supports; older agents send none (agent/COMPATIBILITY.md).
                    'capabilities' => json_encode(array_values(array_filter(
                        (array) ($msg['capabilities'] ?? []),
                        fn ($c) => is_string($c) && strlen($c) <= 40,
                    ))),
                    'last_seen_at' => now(),
                ]);
                break;

            // How a remote upgrade is going, from the agent itself (downloading, restarting,
            // done, failed, rolled_back).
            case 'upgrade_status':
                $stage = (string) ($msg['stage'] ?? '');
                if (in_array($stage, ['downloading', 'restarting', 'done', 'failed', 'rolled_back'], true)) {
                    Agent::where('id', $agentId)->update([
                        'upgrade_status' => $stage,
                        'upgrade_version' => mb_substr((string) ($msg['version'] ?? ''), 0, 40) ?: null,
                        'upgrade_message' => mb_substr((string) ($msg['message'] ?? ''), 0, 500) ?: null,
                        'upgrade_at' => now(),
                    ]);
                    $log = in_array($stage, ['failed', 'rolled_back'], true) ? 'warning' : 'info';
                    EngineLog::$log('agent upgrade: '.$stage, ['id' => $agentId, 'version' => $msg['version'] ?? null, 'message' => $msg['message'] ?? null]);
                }
                $this->heartbeat($agentId);
                break;

            // Poll results / a finished scan: queued, never applied here. Writing a big site's batch
            // inside the event loop held up every other agent's frames and keepalives.
            case 'result':
            case 'discovery':
                if ($this->heartbeat($agentId)) {
                    $kind = $msg['type'] === 'discovery' ? IngestAgentPayloadJob::SCAN : IngestAgentPayloadJob::RESULTS;
                    try {
                        IngestAgentPayloadJob::dispatch($agentId, $kind, (array) ($msg['payload'] ?? []));
                    } catch (\Throwable $e) {
                        EngineLog::warning('agent: could not queue '.$kind, ['id' => $agentId, 'error' => $e->getMessage()]);
                    }
                }
                break;

            // Live scan feedback: a sweep started / is progressing. Scoped to the agent's own
            // subnets so one agent can't touch another's. The `discovery` ingest clears these.
            case 'scan_start':
            case 'scan_progress':
                $sid = (int) ($msg['subnet_id'] ?? 0);
                if ($sid > 0) {
                    Subnet::where('id', $sid)->where('agent_id', $agentId)->update([
                        'scanning_since' => now(),
                        'scan_total' => $msg['total'] ?? null,
                        'scan_swept' => (int) ($msg['swept'] ?? 0),
                        'scan_found' => (int) ($msg['found'] ?? 0),
                    ]);
                }
                $this->heartbeat($agentId);
                break;

            // An agent answering an operator's "Test OID" (#51).
            case 'snmp_test_result':
                TestAgentSnmp::recordReply($agentId, $msg);
                break;
        }
    }

    private function onControl(ConnectionInterface $conn, int $agentId, Frame $frame): void
    {
        $opcode = $frame->getOpcode();

        // Reply to the agent's keepalive pings so its read deadline advances.
        if ($opcode === Frame::OP_PING) {
            $conn->write((new Frame($frame->getPayload(), true, Frame::OP_PONG))->getContents());
        }

        // A pong is the agent's reply to OUR keepalive ping, which carries a send-timestamp
        // ("mm:<ms>"); the round-trip is the agent's current link latency. Cache it with a short
        // TTL so it reads as "current" (and simply disappears when the agent stops answering).
        if ($opcode === Frame::OP_PONG) {
            $payload = $frame->getPayload();
            if (str_starts_with($payload, 'mm:')) {
                $sentMs = (float) substr($payload, 3);
                $rttMs = (microtime(true) * 1000) - $sentMs;
                if ($rttMs >= 0 && $rttMs < 60000) {
                    Cache::put("agent:{$agentId}:latency", round($rttMs, 1), now()->addSeconds(self::KEEPALIVE_SECONDS * 3));
                }
            }
        }

        // Every ping (from the agent) or pong (its reply to our keepalive) is a heartbeat -
        // refresh last_seen so "online" means "heard from within a keepalive or two", and the
        // reaper can flip a silent agent offline promptly instead of waiting on a TCP/proxy
        // timeout to notice the socket died.
        if ($opcode === Frame::OP_PING || $opcode === Frame::OP_PONG) {
            $this->heartbeat($agentId);
        }
    }

    private function onClose(object $state): void
    {
        // Only the agent's current connection counts: a replaced one closing (see onData) mustn't
        // drop the new one or mark the agent offline.
        if ($state->agentId === null || ($this->conns[$state->agentId] ?? null) !== $state->conn) {
            return;
        }
        unset($this->conns[$state->agentId]);
        Agent::where('id', $state->agentId)->update(['status' => AgentStatus::Offline]);
        EngineLog::info('agent disconnected', ['id' => $state->agentId]);
    }

    /**
     * A published dispatch job -> the typed {type, payload} envelopes the agent expects.
     * Ping/SNMP go as one "poll" message; subnets (if any) as a "scan" message.
     */
    private function forwardJob(string $payload): void
    {
        $job = json_decode($payload, true);
        if (! is_array($job) || ! isset($job['agent_id'])) {
            return;
        }
        $agentId = (int) $job['agent_id'];

        if (! empty($job['poll']['ping']) || ! empty($job['poll']['snmp']) || ! empty($job['poll']['routeros'])) {
            $this->send($agentId, ['type' => 'poll', 'payload' => $job['poll']]);
        }
        if (! empty($job['scan']['subnets'])) {
            $this->send($agentId, ['type' => 'scan', 'payload' => $job['scan']]);
        }
        // An admin's remote upgrade - only ever queued for agents that reported the capability.
        if (! empty($job['upgrade'])) {
            $this->send($agentId, ['type' => 'upgrade', 'payload' => $job['upgrade']]);
        }
        // An operator's "Test OID" against one of this agent's devices (#51).
        if (! empty($job['snmp_test'])) {
            $this->send($agentId, ['type' => 'snmp_test', 'payload' => $job['snmp_test']]);
        }
    }

    /**
     * Send a WebSocket ping to every connected agent (keepalive). The agent auto-pongs, echoing the
     * payload - we stamp the send time into it ("mm:<ms>") so the pong handler can derive the link
     * latency from the round-trip.
     */
    private function pingAll(): void
    {
        $payload = 'mm:'.(int) round(microtime(true) * 1000);
        foreach ($this->conns as $conn) {
            $conn->write((new Frame($payload, true, Frame::OP_PING))->getContents());
        }
    }

    private function send(int $agentId, array $payload): void
    {
        $conn = $this->conns[$agentId] ?? null;
        if ($conn === null) {
            return; // agent not connected here - skipped this tick
        }
        $conn->write((new Frame((string) json_encode($payload), true, Frame::OP_TEXT))->getContents());
    }

    /** Seconds before retrying a failed / dropped Redis subscription. */
    private const RESUBSCRIBE_SECONDS = 5;

    private function subscribeRedis($loop): void
    {
        // Laravel's Redis::publish prefixes the channel with the connection prefix
        // (e.g. "my-mate-database-"); subscribe to the SAME prefixed name so we match.
        $channel = (string) config('database.redis.options.prefix', '').DispatchAgentJobs::CHANNEL;
        // Without the subscription the hub keeps every agent connected but sends them nothing, so a
        // failed connect (Redis not up yet at boot) or a dropped one is retried, not just logged.
        $retry = function (string $why, ?\Throwable $e = null) use ($loop): void {
            EngineLog::error("agent hub: redis subscription {$why}, retrying", ['error' => $e?->getMessage()]);
            $loop->addTimer(self::RESUBSCRIBE_SECONDS, fn () => $this->subscribeRedis($loop));
        };

        (new RedisFactory($loop))->createClient($this->redisUri())->then(
            function ($client) use ($channel, $retry) {
                $client->subscribe($channel);
                $client->on('message', fn (string $ch, string $payload) => $this->forwardJob($payload));
                $client->on('close', fn () => $retry('closed'));
                EngineLog::info('agent hub: subscribed to dispatch channel');
            },
            fn (\Throwable $e) => $retry('failed', $e),
        );
    }

    /**
     * Stream a cached upgrade binary to an authenticated agent. Only what PrepareAgentUpgradeJob
     * already fetched and verified is ever served, read in chunks so a slow link doesn't block
     * the loop.
     */
    private function serveBinary(ConnectionInterface $conn, string $version, string $platform): void
    {
        $bin = app(AgentReleases::class)->cached($version, $platform);
        $fh = $bin ? @fopen($bin['path'], 'rb') : false;
        if ($fh === false) {
            $this->reject($conn, 404, 'Not Found');

            return;
        }
        $conn->write("HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nContent-Length: {$bin['size']}\r\n"
            ."X-Content-SHA256: {$bin['sha256']}\r\nConnection: close\r\n\r\n");
        (new ReadableResourceStream($fh))->pipe($conn);
    }

    /**
     * Stamp an agent as heard from. False when the agent no longer exists (deleted while it was
     * connected): its socket is closed then, rather than kept up with everything it sends dropped.
     */
    private function heartbeat(int $agentId): bool
    {
        if (Agent::where('id', $agentId)->update(['last_seen_at' => now()]) > 0) {
            return true;
        }
        EngineLog::info('agent deleted while connected, closing it', ['id' => $agentId]);
        $conn = $this->conns[$agentId] ?? null;
        unset($this->conns[$agentId]);
        $conn?->close();

        return false;
    }

    private function bearer(RequestInterface $request): ?string
    {
        $h = $request->getHeaderLine('Authorization');

        return str_starts_with($h, 'Bearer ') ? substr($h, 7) : null;
    }

    private function reject(ConnectionInterface $conn, int $code, string $reason): void
    {
        $conn->write("HTTP/1.1 $code $reason\r\nConnection: close\r\n\r\n");
        $conn->end();
    }

    private function redisUri(): string
    {
        $c = (array) config('database.redis.default', []);
        $auth = ! empty($c['password']) ? ':'.rawurlencode((string) $c['password']).'@' : '';

        return sprintf('redis://%s%s:%s/%s', $auth, $c['host'] ?? '127.0.0.1', $c['port'] ?? 6379, $c['database'] ?? 0);
    }
}
