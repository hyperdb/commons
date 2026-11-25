<?php

require_once __DIR__ . '/BaseModel.php';


/**
 * モデル: 外部ログインの途中経過（OAuthの状態・一時コード）
 */
class AuthFlowModel
{
    /**
     * OAuthの状態を追加する（発行から5分で失効）
     *
     * @param string $state
     * @param string $provider
     * @param string $code_verifier プロバイダー用のPKCE
     * @param string $code_challenge ブログ用のPKCE
     * @param string $redirect_uri
     * @return int
     */
    public static function addState(
        string $state,
        string $provider,
        string $code_verifier,
        string $code_challenge,
        string $redirect_uri
    ): int {
        $sql = <<< EOS
INSERT INTO oauth_states (
    state
   ,provider
   ,code_verifier
   ,code_challenge
   ,redirect_uri
   ,expired_at
) VALUES (
    ?
   ,?
   ,?
   ,?
   ,?
   ,UNIX_TIMESTAMP() + 5 * 60
)
EOS;
        return BaseModel::query($sql, [
            $state,
            $provider,
            $code_verifier,
            $code_challenge,
            $redirect_uri
        ]);
    }

    /**
     * OAuthの状態を取り出す（取り出したら削除するため、1回しか使えない）
     *
     * @param string $state
     * @param string $provider
     * @return array{code_verifier?: string, code_challenge?: string, redirect_uri?: string}
     *         (無効・期限切れ・プロバイダー違いの場合は空配列)
     */
    public static function takeState(string $state, string $provider): array
    {
        $sql = <<< EOS
SELECT code_verifier
      ,code_challenge
      ,redirect_uri
  FROM oauth_states
 WHERE state = ?
   AND provider = ?
   AND expired_at > UNIX_TIMESTAMP()
EOS;
        $row = BaseModel::fetchRow($sql, [
            $state,
            $provider
        ]);
        // 同時に使われた場合でも、削除できた方だけを有効とする
        if ($row === null || BaseModel::query("DELETE FROM oauth_states WHERE state = ?", [$state]) !== 1)
        {
            return [];
        }
        return $row->getData();
    }

    /**
     * 一時コードを追加する（発行から60秒で失効）
     *
     * @param string $code
     * @param string $uuid 交換する外部トークン（短縮化したものではない）
     * @param string $code_challenge ブログ用のPKCE
     * @return int
     */
    public static function addCode(string $code, string $uuid, string $code_challenge): int
    {
        $sql = <<< EOS
INSERT INTO auth_codes (
    code
   ,token
   ,code_challenge
   ,expired_at
) VALUES (
    ?
   ,?
   ,?
   ,UNIX_TIMESTAMP() + 60
)
EOS;
        return BaseModel::query($sql, [
            $code,
            $uuid,
            $code_challenge
        ]);
    }

    /**
     * 一時コードを取り出す（取り出したら削除するため、1回しか使えない）
     *
     * @param string $code
     * @return array{token?: string, code_challenge?: string} (無効・期限切れの場合は空配列)
     */
    public static function takeCode(string $code): array
    {
        $sql = "SELECT token, code_challenge FROM auth_codes WHERE code = ? AND expired_at > UNIX_TIMESTAMP()";
        $row = BaseModel::fetchRow($sql, [
            $code
        ]);
        // 同時に使われた場合でも、削除できた方だけを有効とする
        if ($row === null || BaseModel::query("DELETE FROM auth_codes WHERE code = ?", [$code]) !== 1)
        {
            return [];
        }
        return $row->getData();
    }

    /**
     * 期限切れのOAuthの状態・一時コードをクリアする
     *
     * @return void
     */
    public static function clearExpired(): void
    {
        BaseModel::query("DELETE FROM oauth_states WHERE expired_at <= UNIX_TIMESTAMP()", []);
        BaseModel::query("DELETE FROM auth_codes WHERE expired_at <= UNIX_TIMESTAMP()", []);
    }
}
