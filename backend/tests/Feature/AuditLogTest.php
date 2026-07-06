<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_model_changes_are_recorded_with_actor_and_diffs(): void
    {
        $admin = User::factory()->admin()->create();
        AuditLog::query()->delete();

        $createResponse = $this->actingAs($admin, 'web')->postJson('/api/customers', [
            'name' => '王小明',
            'phone' => '0900000000',
            'customer_type' => 'buyer',
        ])->assertSuccessful();

        $customerId = $createResponse->json('data.id');

        $created = AuditLog::query()->where('subject_type', 'customer')->where('action', 'created')->sole();
        $this->assertSame($admin->id, $created->actor_id);
        $this->assertSame('王小明', $created->subject_label);
        $this->assertSame('0900000000', $created->after_values['phone']);

        $this->actingAs($admin, 'web')->putJson("/api/customers/{$customerId}", [
            'name' => '王小明',
            'phone' => '0911111111',
            'customer_type' => 'buyer',
        ])->assertSuccessful();

        $updated = AuditLog::query()->where('subject_type', 'customer')->where('action', 'updated')->sole();
        $this->assertSame('0900000000', $updated->before_values['phone']);
        $this->assertSame('0911111111', $updated->after_values['phone']);
        $this->assertArrayNotHasKey('updated_at', $updated->after_values);

        $this->actingAs($admin, 'web')->deleteJson("/api/customers/{$customerId}")->assertSuccessful();

        $deleted = AuditLog::query()->where('subject_type', 'customer')->where('action', 'deleted')->sole();
        $this->assertSame('王小明', $deleted->before_values['name']);
    }

    public function test_sensitive_values_are_never_written_to_audit_log(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();
        AuditLog::query()->delete();

        $this->actingAs($admin, 'web')->postJson("/api/users/{$target->id}/reset-password", [
            'password' => 'NewSecret123!',
            'password_confirmation' => 'NewSecret123!',
        ])->assertSuccessful();

        $log = AuditLog::query()->where('subject_type', 'user')->where('action', 'updated')->sole();
        $encoded = json_encode([$log->before_values, $log->after_values], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('password', $encoded);
        $this->assertStringNotContainsString('NewSecret123!', $encoded);
        $this->assertStringNotContainsString(Hash::make('NewSecret123!'), $encoded);
    }

    public function test_admin_can_read_audit_logs(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'web')->getJson('/api/audit-logs?action=created&subject_type=user');
        $response->assertSuccessful();
        $response->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_manager_cannot_read_audit_logs(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager, 'web')->getJson('/api/audit-logs')->assertForbidden();
    }

    public function test_sales_cannot_read_audit_logs(): void
    {
        $sales = User::factory()->sales()->create();

        $this->actingAs($sales, 'web')->getJson('/api/audit-logs')->assertForbidden();
    }

    public function test_login_and_logout_are_recorded(): void
    {
        $user = User::factory()->admin()->create([
            'email' => 'audit@example.com',
            'password' => Hash::make('password'),
        ]);
        AuditLog::query()->delete();

        $this->withHeaders(['Referer' => 'http://localhost'])->postJson('/api/login', [
            'email' => 'audit@example.com',
            'password' => 'password',
        ])->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $user->id,
            'action' => 'login',
            'subject_type' => 'authentication',
        ]);

        $this->postJson('/api/logout')->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $user->id,
            'action' => 'logout',
            'subject_type' => 'authentication',
        ]);
    }

    public function test_audit_log_model_is_append_only(): void
    {
        $admin = User::factory()->admin()->create();
        $log = AuditLog::query()->latest('id')->firstOrFail();

        $log->subject_label = 'tampered';
        $this->assertFalse($log->save());

        $this->assertFalse($log->delete());
        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'subject_label' => $admin->name.'（'.$admin->email.'）',
        ]);
    }
}
