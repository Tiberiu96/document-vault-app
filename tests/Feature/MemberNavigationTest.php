<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function links(string $html): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//*[@data-member-header]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-member-menu]')->length);
        $links = [];
        foreach ($xpath->query('//*[@data-member-menu]//nav[contains(@class,"member-menu-group")]/a') as $link) {
            $links[] = $link->getAttribute('href');
        }

        return $links;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function workspace(User $owner, string $name): Workspace
    {
        $workspace = Workspace::forceCreate(['owner_id' => $owner->id, 'type' => 'personal', 'name' => $name]);
        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        return $workspace;
    }

    public function test_signed_in_navigation_has_the_same_links_on_public_and_private_pages(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace($user, 'My personal workspace');
        $this->actingAs($user)->withSession(['active_workspace_id' => $workspace->id]);
        $expected = null;
        foreach (['/ro', '/ro/functionalitati', '/ro/contul-meu', '/ro/plan-si-consum', '/ro/documente'] as $url) {
            $response = $this->get($url)->assertOk();
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
            $this->assertTrue($response->headers->hasCacheControlDirective('private'));
            $this->assertFalse($response->headers->hasCacheControlDirective('public'));
            $links = $this->links($response->getContent());
            $expected ??= $links;
            $this->assertSame($expected, $links);
            $this->assertStringNotContainsString('class="library-account"', $response->getContent());
        }
    }

    public function test_guest_home_keeps_public_navigation_without_private_workspace_data(): void
    {
        $this->workspace(User::factory()->create(), 'Private workspace title');
        $this->get('/ro')->assertOk()->assertDontSee('data-member-header', false)->assertDontSee('Private workspace title')->assertDontSee('livewire.js');
    }

    public function test_public_header_does_not_use_a_forged_active_workspace(): void
    {
        $user = User::factory()->create();
        $own = $this->workspace($user, 'Own workspace');
        $foreign = $this->workspace(User::factory()->create(), 'Foreign private workspace');
        $this->actingAs($user)->withSession(['active_workspace_id' => $foreign->id])->get('/ro')->assertOk()->assertSee('Own workspace')->assertDontSee('Foreign private workspace')->assertSessionHas('active_workspace_id', $own->id);
    }

    public function test_public_header_checks_the_authenticated_session_before_rendering_private_data(): void
    {
        $user = User::factory()->create();
        $this->workspace($user, 'Private member data');
        $this->actingAs($user)->withSession(['password_hash_web' => 'obsolete-password-hash'])->get('/ro')->assertRedirect('/ro/autentificare')->assertDontSee('Private member data');
    }

    public function test_unverified_user_has_account_links_without_protected_workspace_links(): void
    {
        $user = User::factory()->unverified()->create();
        $this->workspace($user, 'Unverified workspace');
        $response = $this->actingAs($user)->get('/ro')->assertOk();
        $paths = array_map(fn ($url) => parse_url($url, PHP_URL_PATH), $this->links($response->getContent()));
        $this->assertContains('/ro/contul-meu', $paths);
        $this->assertNotContains('/ro/documente', $paths);
        $this->assertNotContains('/ro/plan-si-consum', $paths);
        $this->assertNotContains('/ro/notificari', $paths);
    }
}
