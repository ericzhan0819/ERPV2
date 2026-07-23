<?php

namespace Tests\Feature;

use App\Http\Requests\UpdateCurrentUserProfileRequest;
use App\Models\User;
use App\Services\UserService;
use App\Support\UsernameRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UsernameValidationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('validUsernameProvider')]
    public function test_username_is_normalized_before_reusable_rules_are_applied(mixed $input, ?string $expected): void
    {
        $normalized = UsernameRules::normalizeInput($input);
        $validator = Validator::make(
            ['username' => $normalized],
            ['username' => UsernameRules::rules()],
            UsernameRules::messages(),
        );

        $this->assertTrue($validator->passes(), implode('；', $validator->errors()->all()));
        $this->assertSame($expected, $validator->validated()['username']);
    }

    public static function validUsernameProvider(): array
    {
        return [
            'null' => [null, null],
            'empty string becomes null' => ['', null],
            'whitespace becomes null' => ['　 ', null],
            'trim and lowercase' => ['  Eric.WANG_01-TEST  ', 'eric.wang_01-test'],
            'minimum length' => ['a-1', 'a-1'],
            'maximum length' => [str_repeat('a', 30), str_repeat('a', 30)],
        ];
    }

    #[DataProvider('invalidUsernameProvider')]
    public function test_username_rejects_invalid_values_with_traditional_chinese_message(
        mixed $input,
        string $expectedMessage,
    ): void {
        $validator = Validator::make(
            ['username' => UsernameRules::normalizeInput($input)],
            ['username' => UsernameRules::rules()],
            UsernameRules::messages(),
        );

        $this->assertTrue($validator->fails());
        $this->assertSame($expectedMessage, $validator->errors()->first('username'));
    }

    public static function invalidUsernameProvider(): array
    {
        return [
            'non-string' => [['eric'], '帳號名稱必須是文字'],
            'too short' => ['ab', '帳號名稱至少需要 3 個字元'],
            'too long' => [str_repeat('a', 31), '帳號名稱不可超過 30 個字元'],
            'at sign' => ['eric@example', '帳號名稱只能包含小寫英文字母、數字、句點、底線與連字號'],
            'space' => ['sales 01', '帳號名稱只能包含小寫英文字母、數字、句點、底線與連字號'],
            'Chinese characters' => ['王小明', '帳號名稱只能包含小寫英文字母、數字、句點、底線與連字號'],
        ];
    }

    public function test_application_unique_rule_is_case_insensitive_after_normalization(): void
    {
        User::factory()->withUsername('Eric')->create();

        $validator = Validator::make(
            ['username' => UsernameRules::normalizeInput(' ERIC ')],
            ['username' => UsernameRules::rules()],
            UsernameRules::messages(),
        );

        $this->assertTrue($validator->fails());
        $this->assertSame('此帳號名稱已被使用', $validator->errors()->first('username'));
    }

    public function test_unique_rule_can_ignore_the_current_user(): void
    {
        $user = User::factory()->withUsername('Eric')->create();

        $validator = Validator::make(
            ['username' => UsernameRules::normalizeInput(' ERIC ')],
            ['username' => UsernameRules::rules($user)],
            UsernameRules::messages(),
        );

        $this->assertTrue($validator->passes());
    }

    public function test_self_profile_request_uses_the_shared_normalization_and_rules(): void
    {
        $user = User::factory()->withUsername('Eric')->create();
        Route::middleware('api')->patch(
            '/api/_test/current-user-profile',
            function (UpdateCurrentUserProfileRequest $request) {
                return response()->json($request->validated());
            },
        );

        $this->actingAs($user)
            ->patchJson('/api/_test/current-user-profile', [
                'name' => '測試使用者',
                'username' => ' ERIC ',
            ])
            ->assertOk()
            ->assertJson([
                'name' => '測試使用者',
                'username' => 'eric',
            ]);
    }

    public function test_self_profile_request_rejects_another_users_normalized_username(): void
    {
        User::factory()->withUsername('Taken.Name')->create();
        $user = User::factory()->create();
        Route::middleware('api')->patch(
            '/api/_test/current-user-profile-unique',
            function (UpdateCurrentUserProfileRequest $request) {
                return response()->json($request->validated());
            },
        );

        $this->actingAs($user)
            ->patchJson('/api/_test/current-user-profile-unique', [
                'name' => '測試使用者',
                'username' => ' TAKEN.NAME ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username'])
            ->assertJsonPath('errors.username.0', '此帳號名稱已被使用');
    }

    public function test_service_converts_a_stale_unique_check_into_username_validation_error(): void
    {
        $winner = User::factory()->withUsername('winner')->create();
        $loser = User::factory()->create();

        try {
            app(UserService::class)->setUsername($loser, ' WINNER ');
            $this->fail('Database unique constraint 必須拒絕重複 username。');
        } catch (ValidationException $exception) {
            $this->assertSame(['此帳號名稱已被使用'], $exception->errors()['username'] ?? null);
        }

        $this->assertSame('winner', $winner->fresh()->username);
        $this->assertNull($loser->fresh()->username);
    }
}
