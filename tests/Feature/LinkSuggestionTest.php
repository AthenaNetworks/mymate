<?php

namespace Tests\Feature;

use App\Actions\Agent\IngestAgentResults;
use App\Actions\Links\RecordNeighbors;
use App\Enums\PollMethod;
use App\Jobs\DiscoverInterfacesJob;
use App\Models\Agent;
use App\Models\Credential;
use App\Models\Device;
use App\Models\Link;
use App\Models\LinkSuggestion;
use App\Models\NetworkInterface;
use App\Models\User;
use App\Services\Neighbors\NeighborTables as T;
use App\Services\RouterOs\RouterOsClient;
use App\Services\Snmp\SnmpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeRouterOsClient;
use Tests\Support\FakeSnmpClient;
use Tests\TestCase;

/** Link suggestions from neighbour discovery (GitHub #85). */
class LinkSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private function iface(Device $d, int $ifIndex, string $name, ?string $description = null): NetworkInterface
    {
        return NetworkInterface::factory()->create(['device_id' => $d->id, 'if_index' => $ifIndex, 'name' => $name, 'description' => $description]);
    }

    /** One LLDP neighbour on local port number $localNum. */
    private function lldp(int $localNum, string $localName, array $remote): T
    {
        $row = "0.{$localNum}.1";

        return new T(walks: array_filter([
            T::LLDP_LOC_PORT_ID => [(string) $localNum => $localName],
            T::LLDP_REM_CHASSIS_ID => isset($remote['chassis']) ? [$row => $remote['chassis']] : null,
            T::LLDP_REM_SYS_NAME => isset($remote['name']) ? [$row => $remote['name']] : null,
            T::LLDP_REM_PORT_ID_SUBTYPE => [$row => '5'],
            T::LLDP_REM_PORT_ID => isset($remote['port']) ? [$row => $remote['port']] : null,
            T::LLDP_REM_MAN_ADDR => isset($remote['ip']) ? [$row.'.1.4.'.$remote['ip'] => '2'] : null,
        ]));
    }

    private function record(Device $d, T $tables): int
    {
        return app(RecordNeighbors::class)($d, $tables);
    }

    public function test_the_local_port_is_matched_by_name_not_the_lldp_port_number(): void
    {
        $sw = Device::factory()->create(['name' => 'comware']);
        $this->iface($sw, 29, 'GigabitEthernet1/0/29'); // what port number 29 would wrongly pick
        $right = $this->iface($sw, 31, 'Ten-GigabitEthernet1/0/29');
        $core = Device::factory()->create(['name' => 'core1']);
        $far = $this->iface($core, 5, 'sfp-sfpplus1');

        $this->assertSame(1, $this->record($sw, $this->lldp(29, 'Ten-GigabitEthernet1/0/29', ['name' => 'core1.example.net', 'port' => 'sfp-sfpplus1'])));

        $s = LinkSuggestion::sole();
        [$aIf, $bIf] = $sw->id < $core->id ? [$right->id, $far->id] : [$far->id, $right->id];
        $this->assertSame([$aIf, $bIf], [$s->a_interface_id, $s->b_interface_id]);
        $this->assertSame('lldp', $s->protocol);
        $this->assertSame('new', $s->status);
    }

    public function test_the_far_device_is_found_by_chassis_id_then_address_and_an_ambiguous_name_matches_nothing(): void
    {
        $sw = Device::factory()->create(['name' => 'edge']);
        $this->iface($sw, 1, 'ether1');
        $byChassis = Device::factory()->create(['name' => 'renamed', 'lldp_chassis_id' => 'aa:bb:cc:dd:ee:ff']);
        $byIp = Device::factory()->create(['name' => 'other', 'mgmt_ip' => '10.13.33.7']);
        Device::factory()->create(['name' => 'dup.site-a']);
        Device::factory()->create(['name' => 'dup.site-b']);

        $this->record($sw, $this->lldp(1, 'ether1', ['chassis' => "\xaa\xbb\xcc\xdd\xee\xff", 'name' => 'nothing-like-it']));
        $this->record($sw, $this->lldp(1, 'ether1', ['name' => 'unknown', 'ip' => '10.13.33.7']));
        $this->record($sw, $this->lldp(1, 'ether1', ['name' => 'dup']));

        $peers = LinkSuggestion::all()->map(fn ($s) => $s->a_device_id === $sw->id ? $s->b_device_id : $s->a_device_id)->sort()->values()->all();
        $this->assertSame(collect([$byChassis->id, $byIp->id])->sort()->values()->all(), $peers);
    }

    public function test_an_unmatched_far_port_still_suggests_the_link_and_the_other_end_completes_it(): void
    {
        $a = Device::factory()->create(['name' => 'alpha']);
        $aPort = $this->iface($a, 1, 'ether1');
        $b = Device::factory()->create(['name' => 'bravo']);
        $bPort = $this->iface($b, 7, 'Gi1/0/7', 'to alpha');

        // alpha hears bravo but bravo's port id means nothing here.
        $this->record($a, $this->lldp(1, 'ether1', ['name' => 'bravo', 'port' => '00:11:22:33:44:55']));
        $partial = LinkSuggestion::sole();
        $this->assertNull($a->id < $b->id ? $partial->b_interface_id : $partial->a_interface_id);
        $partial->update(['status' => 'ignored']);

        // bravo hears alpha with both ports known (the Cisco-style long name matches "Gi1/0/7").
        $this->record($b, new T(walks: [
            T::CDP_DEVICE_ID => ['7.1' => 'alpha'],
            T::CDP_DEVICE_PORT => ['7.1' => 'ether1'],
        ]));

        $s = LinkSuggestion::sole();
        $this->assertEqualsCanonicalizing([$aPort->id, $bPort->id], [$s->a_interface_id, $s->b_interface_id]);
        $this->assertSame('ignored', $s->status); // the ignore carries over to the fuller one
    }

    public function test_short_and_long_port_names_match(): void
    {
        $a = Device::factory()->create(['name' => 'alpha']);
        $this->iface($a, 1, 'ether1');
        $b = Device::factory()->create(['name' => 'bravo']);
        $long = $this->iface($b, 3, 'GigabitEthernet1/0/3');

        $this->record($a, $this->lldp(1, 'ether1', ['name' => 'bravo', 'port' => 'Gi1/0/3']));

        $s = LinkSuggestion::sole();
        $this->assertContains($long->id, [$s->a_interface_id, $s->b_interface_id]);
    }

    public function test_a_cable_an_existing_link_covers_is_not_suggested_and_each_lag_member_is(): void
    {
        $a = Device::factory()->create(['name' => 'alpha']);
        $a1 = $this->iface($a, 1, 'ether1');
        $this->iface($a, 2, 'ether2');
        $b = Device::factory()->create(['name' => 'bravo']);
        $b1 = $this->iface($b, 1, 'ether1');
        $this->iface($b, 2, 'ether2');
        Link::create(['a_device_id' => $a->id, 'a_interface_id' => $a1->id, 'b_device_id' => $b->id, 'b_interface_id' => $b1->id]);

        $this->record($a, $this->lldp(1, 'ether1', ['name' => 'bravo', 'port' => 'ether1']));
        $this->assertSame(0, LinkSuggestion::count());

        $this->record($a, $this->lldp(2, 'ether2', ['name' => 'bravo', 'port' => 'ether2']));
        $this->assertSame(1, LinkSuggestion::count());
    }

    public function test_the_own_chassis_id_is_stored_and_stale_suggestions_are_dropped(): void
    {
        $a = Device::factory()->create();
        $other = Device::factory()->create();
        LinkSuggestion::create([
            'a_device_id' => min($a->id, $other->id), 'b_device_id' => max($a->id, $other->id),
            'pair_key' => 'old', 'protocol' => 'lldp', 'first_seen_at' => now()->subWeek(), 'last_seen_at' => now()->subWeek(),
        ]);

        $this->record($a, new T(scalars: [T::LLDP_LOC_CHASSIS_ID => "\x00\x01\x02\x03\x04\x05"]));

        $this->assertSame('00:01:02:03:04:05', $a->fresh()->lldp_chassis_id);
        $this->assertSame(0, LinkSuggestion::count());
    }

    public function test_central_discovery_reads_lldp_over_snmp(): void
    {
        $cred = Credential::factory()->create(['type' => 'snmp', 'snmp_community' => 'public']);
        $sw = Device::factory()->create(['name' => 'sw1', 'poll_method' => PollMethod::Snmp, 'credential_id' => $cred->id]);
        $peer = Device::factory()->create(['name' => 'sw2']);
        $this->iface($peer, 2, 'ether2');

        $snmp = new FakeSnmpClient;
        $snmp->walks = [
            '.1.3.6.1.2.1.31.1.1.1.1' => ['1' => 'ether1'], // ifName
            T::LLDP_LOC_PORT_ID => ['1' => 'ether1'],
            T::LLDP_REM_CHASSIS_ID => ['0.1.1' => "\x00\x00\x00\x00\x00\x02"],
            T::LLDP_REM_SYS_NAME => ['0.1.1' => 'sw2'],
            T::LLDP_REM_PORT_ID => ['0.1.1' => 'ether2'],
        ];
        $this->app->instance(SnmpClient::class, $snmp);

        (new DiscoverInterfacesJob($sw->id))->handle(app(\App\Actions\Polling\DiscoverInterfaces::class), app(\App\Actions\Devices\CaptureDeviceFacts::class));

        $s = LinkSuggestion::sole();
        $this->assertEqualsCanonicalizing([$sw->id, $peer->id], [$s->a_device_id, $s->b_device_id]);
        $this->assertNotNull($s->a_interface_id);
        $this->assertNotNull($s->b_interface_id);
    }

    public function test_central_discovery_reads_routeros_neighbors_over_the_api(): void
    {
        $cred = Credential::factory()->create(['type' => 'routeros', 'username' => 'api', 'password' => 'x']);
        $mt = Device::factory()->create(['name' => 'mt1', 'poll_method' => PollMethod::RouterOs, 'credential_id' => $cred->id]);
        $this->iface($mt, 1, 'ether1');
        $peer = Device::factory()->create(['name' => 'mt2']);
        $this->iface($peer, 4, 'ether4');
        $this->app->instance(RouterOsClient::class, new FakeRouterOsClient([
            '/ip/neighbor/print' => [['interface' => 'ether1', 'identity' => 'mt2', 'interface-name' => 'ether4', 'discovered-by' => 'mndp']],
        ]));

        $tables = app(\App\Actions\Links\ReadNeighbors::class)($mt);
        $this->record($mt, $tables);

        $s = LinkSuggestion::sole();
        $this->assertSame('mndp', $s->protocol);
        $this->assertNotNull($s->a_interface_id);
        $this->assertNotNull($s->b_interface_id);
    }

    public function test_agent_discovery_with_neighbours_suggests_links_and_an_older_agent_without_them_is_fine(): void
    {
        $agent = Agent::factory()->create();
        $dev = Device::factory()->create(['name' => 'remote-sw', 'agent_id' => $agent->id]);
        $peer = Device::factory()->create(['name' => 'remote-core']);

        // Older agent: no `neighbors` key at all.
        app(IngestAgentResults::class)($agent, ['discovery' => [['device_id' => $dev->id, 'interfaces' => [['if_index' => 1, 'name' => 'ether1']]]]]);
        $this->assertSame(0, LinkSuggestion::count());

        app(IngestAgentResults::class)($agent, ['discovery' => [[
            'device_id' => $dev->id,
            'interfaces' => [['if_index' => 1, 'name' => 'ether1']],
            'neighbors' => [
                'walks' => [
                    T::LLDP_LOC_PORT_ID => ['1' => 'ether1'],
                    T::LLDP_REM_CHASSIS_ID => ['0.1.1' => 'hex:0a0000000001'],
                    T::LLDP_REM_SYS_NAME => ['0.1.1' => 'remote-core'],
                ],
                'scalars' => [T::LLDP_LOC_CHASSIS_ID => 'hex:0a0000000002'],
            ],
        ]]]);

        $s = LinkSuggestion::sole();
        $this->assertEqualsCanonicalizing([$dev->id, $peer->id], [$s->a_device_id, $s->b_device_id]);
        $this->assertSame('0a:00:00:00:00:02', $dev->fresh()->lldp_chassis_id);
    }

    private function suggestion(): LinkSuggestion
    {
        $a = Device::factory()->create(['name' => 'alpha']);
        $aIf = $this->iface($a, 1, 'ether1');
        $b = Device::factory()->create(['name' => 'bravo']);
        $bIf = $this->iface($b, 1, 'ether1');

        return LinkSuggestion::create([
            'a_device_id' => $a->id, 'a_interface_id' => $aIf->id, 'b_device_id' => $b->id, 'b_interface_id' => $bIf->id,
            'pair_key' => LinkSuggestion::key($a->id, $aIf->id, $b->id, $bIf->id),
            'protocol' => 'lldp', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    public function test_accepting_draws_the_link_and_clears_the_suggestion(): void
    {
        $this->actingAsUser();
        $s = $this->suggestion();

        $this->getJson('/api/link-suggestions')->assertOk()
            ->assertJsonPath('data.0.a.device_name', 'alpha')->assertJsonPath('data.0.b.interface_name', 'ether1');

        $this->postJson("/api/link-suggestions/{$s->id}/accept")->assertCreated();

        $this->assertDatabaseHas('links', ['a_device_id' => $s->a_device_id, 'a_interface_id' => $s->a_interface_id, 'b_interface_id' => $s->b_interface_id]);
        $this->assertSame(0, LinkSuggestion::count());
    }

    public function test_accept_all_ignore_and_restore(): void
    {
        $this->actingAsUser();
        $one = $this->suggestion();
        $two = $this->suggestion();

        $this->postJson("/api/link-suggestions/{$two->id}/ignore")->assertOk()->assertJsonPath('data.status', 'ignored');
        $this->getJson('/api/link-suggestions?status=ignored')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/link-suggestions/{$one->id}/restore")->assertStatus(422);

        $this->postJson('/api/link-suggestions/accept-all')->assertOk()->assertJsonPath('data.created', 1);
        $this->assertSame(1, Link::count());
        $this->assertSame('ignored', $two->fresh()->status);

        $this->postJson("/api/link-suggestions/{$two->id}/restore")->assertOk()->assertJsonPath('data.status', 'new');
    }

    public function test_a_suggestion_covered_by_a_link_drawn_since_is_hidden(): void
    {
        $this->actingAsUser();
        $s = $this->suggestion();
        Link::create(['a_device_id' => $s->a_device_id, 'b_device_id' => $s->b_device_id]);

        $this->getJson('/api/link-suggestions')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_operators_can_look_but_only_admins_can_accept_and_restricted_operators_see_nothing(): void
    {
        $s = $this->suggestion();

        $this->actingAs(User::factory()->create());
        $this->getJson('/api/link-suggestions')->assertOk();
        $this->postJson("/api/link-suggestions/{$s->id}/accept")->assertForbidden();
        $this->postJson('/api/link-suggestions/accept-all')->assertForbidden();

        $this->actingAs(User::factory()->create(['restricted' => true]));
        $this->getJson('/api/link-suggestions')->assertForbidden();
    }
}
