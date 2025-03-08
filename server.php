<?php
// Permitir requisições de qualquer origem
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Responder para requisições OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("HTTP/1.1 200 OK");
    exit();
}

// Responder para requisições GET (para o UptimeRobot)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo "App Heroku está funcionando!";
    exit();
}

// Ler os dados recebidos do webhook
$dadosWebhook = json_decode(file_get_contents('php://input'), true);

// Salvar os dados no arquivo de log (para depuração)
file_put_contents('webhook.log', json_encode($dadosWebhook, JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);

// Verificar se os dados foram recebidos corretamente
if (empty($dadosWebhook)) {
    die('Erro: Nenhum dado recebido do webhook.');
}

// Extrair os parâmetros UTM da URL da página de confirmação
$confirmationUrl = $dadosWebhook['confirmationUrl'] ?? ''; // Supondo que a URL esteja nos dados do webhook
$queryString = parse_url($confirmationUrl, PHP_URL_QUERY);
parse_str($queryString, $queryParams);

// Filtrar apenas os parâmetros UTM
$utmParams = [
    'utm_source' => $queryParams['utm_source'] ?? null,
    'utm_campaign' => $queryParams['utm_campaign'] ?? null,
    'utm_medium' => $queryParams['utm_medium'] ?? null,
    'utm_content' => $queryParams['utm_content'] ?? null,
    'utm_term' => $queryParams['utm_term'] ?? null
];

// Definir o status da UTMify com base no status da GhostPay
$statusUtmify = ($dadosWebhook['status'] === 'APPROVED') ? 'paid' : 'waiting_payment';

// Montar os dados para a UTMify
$dadosUtmify = [
    'orderId' => $dadosWebhook['customId'],
    'platform' => 'GhostPay',
    'paymentMethod' => 'pix',
    'status' => $statusUtmify,
    'createdAt' => $dadosWebhook['createdAt'],
    'approvedDate' => ($statusUtmify === 'paid') ? $dadosWebhook['approvedAt'] : null,
    'refundedAt' => null,
    'customer' => [
        'name' => $dadosWebhook['customer']['name'],
        'email' => $dadosWebhook['customer']['email'],
        'phone' => $dadosWebhook['customer']['phone'],
        'document' => $dadosWebhook['customer']['cpf'],
        'country' => 'BR',
        'ip' => '200.150.100.50' // Substitua pelo IP real, se disponível
    ],
    'products' => [
        [
            'id' => 'taxinha_do_amor',
            'name' => 'Taxinha do amor',
            'planId' => null,
            'planName' => null,
            'quantity' => 1,
            'priceInCents' => $dadosWebhook['totalValue']
        ]
    ],
    'trackingParameters' => [
        'src' => null,
        'sck' => null,
        'utm_source' => $utmParams['utm_source'],
        'utm_campaign' => $utmParams['utm_campaign'],
        'utm_medium' => $utmParams['utm_medium'],
        'utm_content' => $utmParams['utm_content'],
        'utm_term' => $utmParams['utm_term']
    ],
    'commission' => [
        'totalPriceInCents' => $dadosWebhook['totalValue'],
        'gatewayFeeInCents' => $dadosWebhook['totalValue'] - $dadosWebhook['netValue'],
        'userCommissionInCents' => $dadosWebhook['netValue']
    ],
    'isTest' => false
];

// Enviar os dados para a UTMify
$urlUtmify = 'https://api.utmify.com.br/api-credentials/orders';
$tokenUtmify = '6h97ECAda2NTAmzBXkdQY4p3xdaXMSY1d0Sh'; // Substitua pelo seu token da UTMify

$headers = [
    'x-api-token: ' . $tokenUtmify,
    'Content-Type: application/json'
];

$ch = curl_init($urlUtmify);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dadosUtmify));
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

// Salvar a resposta da UTMify no arquivo de log
file_put_contents('utmify_response.log', "HTTP Code: $httpCode\nResponse: $response\n", FILE_APPEND);

if ($httpCode === 200) {
    echo 'Dados enviados para a UTMify com sucesso!';
} else {
    echo "Erro ao enviar dados para a UTMify. Código HTTP: $httpCode\nResposta: $response";
}