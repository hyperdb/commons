<?php

use flight\Engine;

/**
 * Undocumented class
 */
class CorsUtil
{
    protected Engine $app;

    /**
     * Undocumented function
     *
     * @param Engine $app
     */
    public function __construct(Engine $app)
    {
        $this->app = $app;
    }

    /**
     * Undocumented function
     *
     * @param array $params
     * @return void
     */
    public function set(array $params = []): void
    {
        $request = $this->app->request();
        $response = $this->app->response();
        if ($request->getVar('HTTP_ORIGIN') !== '')
        {
            $this->allowOrigins();
            $response->header('Access-Control-Allow-Credentials', 'true');
            $response->header('Access-Control-Max-Age', '86400');
        }

        if ($request->method === 'OPTIONS')
        {
            if ($request->getVar('HTTP_ACCESS_CONTROL_REQUEST_METHOD') !== '')
            {
                $response->header(
                    'Access-Control-Allow-Methods',
                    'GET, POST, PUT, DELETE, PATCH, OPTIONS, HEAD'
                );
            }
            if ($request->getVar('HTTP_ACCESS_CONTROL_REQUEST_HEADERS') !== '')
            {
                $response->header(
                    "Access-Control-Allow-Headers",
                    $request->getVar('HTTP_ACCESS_CONTROL_REQUEST_HEADERS')
                );
            }

            $response->status(200);
            $response->send();
            exit;
        }
    }

    /**
     * Undocumented function
     *
     * @return void
     */
    private function allowOrigins(): void
    {
        // 許可するホストをここでカスタマイズします。
        $allowed = [
            'capacitor://localhost',
            'ionic://localhost',
            'http://localhost',
            'http://localhost:5173',    // react
            'http://localhost:8001',    // php
            'http://localhost:3000',    // nextjs
        ];

        $request = $this->app->request();

        if (in_array($request->getVar('HTTP_ORIGIN'), $allowed, true) === true)
        {
            $response = $this->app->response();
            $response->header("Access-Control-Allow-Origin", $request->getVar('HTTP_ORIGIN'));
        }
    }
}
