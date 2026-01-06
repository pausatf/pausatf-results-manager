<?php

declare(strict_types=1);

/**
 * Inherited Methods
 * @method void wantToTest($text)
 * @method void wantTo($text)
 * @method void execute($callable)
 * @method void expectTo($prediction)
 * @method void expect($prediction)
 * @method void amGoingTo($argumentation)
 * @method void am($role)
 * @method void lookForwardTo($achieveValue)
 * @method void comment($description)
 * @method void pause()
 *
 * @SuppressWarnings(PHPMD)
*/
class ApiTester extends \Codeception\Actor
{
    use _generated\ApiTesterActions;

    /**
     * Authenticate for API requests
     */
    public function authenticate(): void
    {
        $username = getenv('WP_ADMIN_USERNAME') ?: 'admin';
        $password = getenv('WP_ADMIN_PASSWORD') ?: 'admin';

        $this->haveHttpHeader('Authorization', 'Basic ' . base64_encode("{$username}:{$password}"));
    }

    /**
     * Send JSON payload
     */
    public function sendJsonPayload(string $method, string $url, array $data): void
    {
        $this->haveHttpHeader('Content-Type', 'application/json');

        switch (strtoupper($method)) {
            case 'POST':
                $this->sendPost($url, json_encode($data));
                break;
            case 'PUT':
                $this->sendPut($url, json_encode($data));
                break;
            case 'PATCH':
                $this->sendPatch($url, json_encode($data));
                break;
        }
    }

    /**
     * Verify REST API namespace exists
     */
    public function seeNamespaceExists(string $namespace): void
    {
        $this->sendGet('/wp-json/');
        $this->seeResponseCodeIs(200);
        $this->seeResponseContainsJson(['namespaces' => [$namespace]]);
    }
}
