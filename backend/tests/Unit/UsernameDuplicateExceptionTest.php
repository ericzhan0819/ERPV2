<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UsernameDuplicateExceptionTest extends TestCase
{
    #[DataProvider('mysqlUsernameDuplicateMessageProvider')]
    public function test_mysql_username_duplicate_formats_are_converted_without_a_database(
        string $driverMessage,
    ): void {
        $exception = $this->mysqlDuplicateException($driverMessage);
        $user = $this->userWhoseSaveThrows($exception);

        try {
            (new UserService)->setUsername($user, 'eric');
            $this->fail('MySQL username duplicate 必須轉為 ValidationException。');
        } catch (ValidationException $validationException) {
            $this->assertSame(
                ['此帳號名稱已被使用'],
                $validationException->errors()['username'] ?? null,
            );
        }
    }

    public static function mysqlUsernameDuplicateMessageProvider(): array
    {
        return [
            'MariaDB and older MySQL' => [
                "Duplicate entry 'eric' for key 'users_username_unique'",
            ],
            'MySQL 8.0.19 and newer' => [
                "Duplicate entry 'eric' for key 'users.users_username_unique'",
            ],
        ];
    }

    public function test_non_username_mysql_duplicate_is_rethrown_unchanged(): void
    {
        $exception = $this->mysqlDuplicateException(
            "Duplicate entry 'eric@example.com' for key 'users.users_email_unique'",
        );
        $user = $this->userWhoseSaveThrows($exception);

        try {
            (new UserService)->setUsername($user, 'eric');
            $this->fail('非 username constraint 不得被誤轉為 username validation error。');
        } catch (QueryException $caught) {
            $this->assertSame($exception, $caught);
        }
    }

    private function mysqlDuplicateException(string $message): QueryException
    {
        $previous = new PDOException($message, 23000);
        $previous->errorInfo = ['23000', 1062, $message];

        return new QueryException(
            'mysql',
            'update `users` set `username` = ? where `id` = ?',
            ['eric', 1],
            $previous,
        );
    }

    private function userWhoseSaveThrows(QueryException $exception): User
    {
        return new class($exception) extends User
        {
            public function __construct(private readonly ?QueryException $saveException = null)
            {
                parent::__construct();
            }

            public function save(array $options = []): bool
            {
                if ($this->saveException === null) {
                    return parent::save($options);
                }

                throw $this->saveException;
            }
        };
    }
}
