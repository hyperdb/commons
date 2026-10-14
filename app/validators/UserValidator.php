<?php

use App\Shared\Utils\BaseValidator;

/**
 * バリデーションクラス: ユーザー
 */
class UserValidator extends BaseValidator
{
    /**
     * プロパティ設定
     */
    public string $id;
    public string $password;
    public string $user_level;

    /**
     * バリデーションルール
     *
     * @var array
     */
    protected array $rules = [
        "id" => [
            "label" => "ユーザーID",
            "pattern" => [
                "required",     // 必須
                "minLength:4",  // 4文字以上
                "maxLength:32", // 32文字以下
                ["matchPattern", "/^[a-z0-9]+$/"]   // 半角英小文字・数字のみ
            ]
        ],
        "password" => [
            "label" => "パスワード",
            "pattern" => [
                "required",     // 必須
                "minLength:8",  // 8文字以上
                "maxLength:72", // 72文字以下（bcryptの上限）
                ["matchPattern", "/^[\x21-\x7e]+$/"],   // 半角の英数字・記号のみ（空白不可）
                "useAllChar"    // すべての文字種
            ]
        ],
        "user_level" => [
            "label" => "権限レベル",
            "pattern" => [
                "required",     // 必須
                ["matchPattern", "/^(000|001|010|111)$/"]   // 定義済みの権限レベルのみ
            ]
        ],
    ];
}
