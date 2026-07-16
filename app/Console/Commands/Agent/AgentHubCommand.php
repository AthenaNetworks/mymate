<?php

namespace App\Console\Commands\Agent;

use App\Actions\Agent\DispatchAgentJobs;
use App\Actions\Agent\IngestAgentResults;
use App\Actions\Agent\IngestAgentScan;
use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Support\EngineLog;
use Clue\React\Redis\Factory as RedisFactory;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Message as Psr7Message;
use Illuminate\Console\Command;
use Psr\Http\Message\RequestInterface;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\Message;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

/**
 * The remote-agent WebSocket hub. Agents dial in over TLS (nginx terminates + proxies /agent
 * here), authenticate with a Bearer token, and stay connected; the hub marks them online,
 * folds their results into the pipeline ({@see IngestAgentResults}), and forwards poll/scan
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
        $state = (object) ['handshaked' => false, 'raw' => '', 'agentId' => null, 'mb' => null];

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
                    'last_seen_at' => now(),
                ]);
                break;

            case 'result':
                if ($agent = Agent::find($agentId)) {
                    try {
                        app(IngestAgentResults::class)($agent, $msg['payload'] ?? []);
                    } catch (\Throwable $e) {
                        EngineLog::warning('agent: result ingest failed', ['id' => $agentId, 'error' => $e->getMessage()]);
                    }
                }
                Agent::where('id', $agentId)->update(['last_seen_at' => now()]);
                break;

            case 'discovery':
                if ($agent = Agent::find($agentId)) {
                    try {
                        app(IngestAgentScan::class)($agent, $msg['payload'] ?? []);
                    } catch (\Throwable $e) {
                        EngineLog::warning('agent: scan ingest failed', ['id' => $agentId, 'error' => $e->getMessage()]);
                    }
                }
                Agent::where('id', $agentId)->update(['last_seen_at' => now()]);
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

        // Every ping (from the agent) or pong (its reply to our keepalive) is a heartbeat -
        // refresh last_seen so "online" means "heard from within a keepalive or two", and the
        // reaper can flip a silent agent offline promptly instead of waiting on a TCP/proxy
        // timeout to notice the socket died.
        if ($opcode === Frame::OP_PING || $opcode === Frame::OP_PONG) {
            Agent::where('id', $agentId)->update(['last_seen_at' => now()]);
        }
    }

    private function onClose(object $state): void
    {
        if ($state->agentId === null) {
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
    }

    /** Send a WebSocket ping to every connected agent (keepalive). The agent auto-pongs. */
    private function pingAll(): void
    {
        foreach ($this->conns as $conn) {
            $conn->write((new Frame('mm', true, Frame::OP_PING))->getContents());
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

    private function subscribeRedis($loop): void
    {
        // Laravel's Redis::publish prefixes the channel with the connection prefix
        // (e.g. "my-mate-database-"); subscribe to the SAME prefixed name so we match.
        $channel = (string) config('database.redis.options.prefix', '').DispatchAgentJobs::CHANNEL;

        (new RedisFactory($loop))->createClient($this->redisUri())->then(
            function ($client) use ($channel) {
                $client->subscribe($channel);
                $client->on('message', fn (string $ch, string $payload) => $this->forwardJob($payload));
                EngineLog::info('agent hub: subscribed to dispatch channel');
            },
            fn ($e) => EngineLog::error('agent hub: redis subscribe failed', ['error' => $e->getMessage()]),
        );
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
