<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeShirtMilestone;
use App\Models\HiringOutboxEvent;
use App\Models\ShirtColor;
use App\Models\ShirtLogo;
use App\Models\Store;
use App\Services\ShirtCatalogService;
use App\Services\ShirtMilestoneWorkflowService;
use App\Services\Shirts\ShirtAssetStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShirtCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        // The outbox row is what these tests assert on; publishing it to NATS
        // is PublishOutboxEventJob's job and needs a broker.
        Queue::fake();
    }

    /**
     * The frontend INLINES the template SVG so it can recolour it, which makes
     * an uploaded file executable markup in the app's own origin.
     */
    public function test_uploaded_svg_is_stripped_of_scripts(): void
    {
        $evil = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 400">
          <script>alert(document.cookie)</script>
          <path class="shirt-fill" fill="currentColor" d="M10 10 H 390 V 390 H 10 Z" onload="alert(1)"/>
          <image href="http://evil.example/pixel.png"/>
          <a xlink:href="javascript:alert(2)"><text>x</text></a>
        </svg>
        SVG;

        $template = app(ShirtCatalogService::class)->createTemplate(
            [
                'name' => 'Classic Tee',
                'gender' => null,
                'print_area' => ['x' => 120, 'y' => 150, 'width' => 160, 'height' => 120],
            ],
            UploadedFile::fake()->createWithContent('shirt.svg', $evil)
        );

        $stored = Storage::disk('public')->get($template->svg_path);

        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onload', $stored);
        $this->assertStringNotContainsString('evil.example', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);

        // The one thing that MUST survive: recolouring depends on it.
        $this->assertStringContainsString('currentColor', $stored);
    }

    public function test_non_svg_content_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        app(ShirtAssetStorage::class)->storeSvg(
            UploadedFile::fake()->createWithContent('not-really.svg', 'just some text'),
            'shirt-templates'
        );
    }

    /**
     * Logos never change colour, so they are stored exactly as uploaded — no
     * conversion in either direction.
     */
    public function test_png_logos_are_stored_untouched(): void
    {
        $logo = app(ShirtCatalogService::class)->createLogo(
            ['name' => 'Wordmark'],
            UploadedFile::fake()->image('wordmark.png', 200, 80)
        );

        $this->assertSame('image/png', $logo->mime_type);
        Storage::disk('public')->assertExists($logo->file_path);
    }

    public function test_hex_codes_are_normalized(): void
    {
        $service = app(ShirtCatalogService::class);

        $this->assertSame('#CC1133', $service->createColor(['name' => 'A', 'hex_code' => 'c13'])->hex_code);
        $this->assertSame('#C8102E', $service->createColor(['name' => 'B', 'hex_code' => '#c8102e'])->hex_code);
    }

    /**
     * One catalogue endpoint serves both audiences: the entry form must never
     * offer something that can no longer be ordered, while the management
     * screens still need to see what has been retired.
     */
    public function test_catalog_hides_inactive_rows_unless_asked(): void
    {
        $service = app(ShirtCatalogService::class);

        $service->createColor(['name' => 'Live', 'hex_code' => '#000000']);
        $retired = $service->createColor(['name' => 'Retired', 'hex_code' => '#FFFFFF']);
        $service->deactivateColor($retired);

        $default = collect($service->catalog()['colors'])->pluck('name');
        $this->assertTrue($default->contains('Live'));
        $this->assertFalse($default->contains('Retired'));

        $all = collect($service->catalog(includeInactive: true)['colors'])->pluck('name');
        $this->assertTrue($all->contains('Live'));
        $this->assertTrue($all->contains('Retired'));

        // Deactivated, not deleted — historical milestones still point at it.
        $this->assertNotNull(ShirtColor::query()->find($retired->id));
    }

    public function test_catalog_returns_all_three_groups(): void
    {
        $catalog = app(ShirtCatalogService::class)->catalog();

        $this->assertSame(['colors', 'logos', 'templates'], array_keys($catalog));
    }

    /**
     * Submitting an entry must queue a notification for the fulfilment role —
     * but only once that role has actually been named.
     */
    public function test_submitted_entry_notifies_only_when_a_role_is_configured(): void
    {
        [$store, $milestone] = $this->makeMilestone();

        $color = ShirtColor::query()->create(['name' => 'Red', 'hex_code' => '#C8102E']);
        $logo = ShirtLogo::query()->create([
            'name' => 'Primary',
            'file_path' => 'shirt-logos/x.svg',
            'mime_type' => 'image/svg+xml',
        ]);

        $payload = [
            'shirt_color_id' => $color->id,
            'shirt_logo_id' => $logo->id,
            't_shirt_size' => 'L',
        ];

        config()->set('shirt_milestones.roles.fulfilment', []);
        app(ShirtMilestoneWorkflowService::class)->submitEntry($store, $milestone, $payload);

        $this->assertSame(0, HiringOutboxEvent::query()->count(), 'no role configured means no send');

        // Now name the role and do it again on a fresh milestone.
        config()->set('shirt_milestones.roles.fulfilment', ['Uniform Coordinator']);
        [, $second] = $this->makeMilestone(month: 2);
        app(ShirtMilestoneWorkflowService::class)->submitEntry($store, $second, $payload);

        $event = HiringOutboxEvent::query()->latest('id')->firstOrFail();
        $data = $event->payload['data'];

        $this->assertSame(['Uniform Coordinator'], $data['roles']);
        // NotificationsPizza matches on the store NUMBER, never the numeric id.
        $this->assertSame([$store->store_number], $data['stores']);
        $this->assertSame('shirt_milestone_submitted', $data['payload']['type']);
    }

    /**
     * The entry form decides whether the size field is mandatory from the
     * milestone payload alone, so the size has to ride along — while the rest
     * of the obsession row (race, religion, birth date) must not.
     */
    public function test_milestone_payload_carries_the_shirt_size_and_nothing_sensitive(): void
    {
        [$store, $milestone] = $this->makeMilestone();

        DB::table('employee_obsessions')->insert([
            'employee_id' => $milestone->employee_id,
            't_shirt' => 'M',
            'birth_date' => '1990-04-02',
            'religion' => 'Other',
            'race' => 'Other',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = app(ShirtMilestoneWorkflowService::class)
            ->indexForStore($store, [])
            ->items()[0]
            ->toArray();

        $this->assertSame('M', $row['employee']['obsession']['t_shirt']);

        foreach (['birth_date', 'religion', 'race', 'notes'] as $sensitive) {
            $this->assertArrayNotHasKey($sensitive, $row['employee']['obsession']);
        }
    }
    public function test_a_milestone_cannot_be_touched_through_another_store(): void
    {
        [, $milestone] = $this->makeMilestone();

        $otherStore = Store::query()->create(['id' => 2, 'store_number' => '03759-00002']);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        app(ShirtMilestoneWorkflowService::class)->submitEntry($otherStore, $milestone, [
            'shirt_color_id' => 1,
            'shirt_logo_id' => 1,
        ]);
    }

    // -------------------------------------------------------------------------

    /** @return array{0: Store, 1: EmployeeShirtMilestone} */
    private function makeMilestone(int $month = 1): array
    {
        $store = Store::query()->firstOrCreate(['id' => 1], ['store_number' => '03759-00001']);

        $employee = Employee::query()->create([
            'first_name' => 'Dana',
            'last_name' => 'Rivera',
            'gender' => 'female',
            'ssn' => '000-00-0000',
            'employment_type' => 'W2',
        ]);

        DB::table('employee_stores')->insert([
            'employee_id' => $employee->id,
            'store_id' => $store->id,
            'effective_date' => '2026-01-15',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $milestone = EmployeeShirtMilestone::query()->create([
            'employee_id' => $employee->id,
            'store_id' => $store->id,
            'milestone_month' => $month,
            'stint_start_date' => '2026-01-15',
            'due_date' => '2026-02-15',
            'source' => 'automatic',
            'status' => 'pending_entry',
        ]);

        return [$store, $milestone];
    }
}
