<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * アプリのほぼ全ルートが auth ミドルウェアの内側にあるため、
     * 既定でログイン済みの状態からテストを始める。
     *
     * 認証そのものを検証するテストは false にする。
     */
    protected bool $authenticateByDefault = true;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->authenticateByDefault) {
            $this->actingAs($this->fakeUser());
        }
    }

    /**
     * DBに保存しないダミーユーザー。
     *
     * users テーブルを用意していないテスト（パーサ単体のテストなど）でも
     * 認証を通せるように、あえて永続化しない。
     */
    protected function fakeUser(): User
    {
        return (new User)->forceFill([
            'id' => 1,
            'name' => 'テストユーザー',
            'email' => 'test@example.com',
        ]);
    }
}
