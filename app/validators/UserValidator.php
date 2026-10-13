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
                "maxLength:16"  // 16文字以下
            ]
        ],
        "password" => [
            "label" => "パスワード",
            "pattern" => [
                "required",     // 必須
                "minLength:8",  // 8文字以上
                "useAllChar"    // すべての文字種
            ]
        ],
    ];
}
