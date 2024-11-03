<?php
// Carrega configurações de token e IP
$configFile = '/opt/mk-auth/dados/Recibo_Whatsapp/config.php';
if (file_exists($configFile)) {
    $config = include($configFile);
    $token = $config['token'];
    $ip = $config['ip'];
    $apiBaseURL = "http://$ip/mikrotik/$token/"; // URL base da API ajustada
} else {
    die("Erro: Arquivo de configuração não encontrado.");
}

// Configurações do banco de dados
$host = "localhost";
$usuario = "root";
$senha = "vertrigo";
$db = "mkradius";

// Conexão com o banco de dados
$con = new mysqli($host, $usuario, $senha, $db);
if ($con->connect_error) {
    die("Erro ao conectar ao banco de dados: " . $con->connect_error);
}

// Arquivo de log
$logFile = '/opt/mk-auth/dados/Recibo_Whatsapp/log_pagamentos.txt';

// Consulta para ler registros não enviados da tabela brl_pago
$query = "SELECT * FROM brl_pago WHERE envio = 0";
$stmt = $con->prepare($query);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    // Extrai e formata os dados
    $login = $row['login'];
    $datapag = date('d/m/Y', strtotime($row['datapag']));
    $datavenc = date('d/m/Y', strtotime($row['datavenc']));
    $valor = number_format($row['valor'], 2, ',', '.');
    $valorpag = number_format($row['valorpag'], 2, ',', '.');
    $coletor = $row['coletor'];
    $formapag = $row['formapag'];

    // Busca o nome e o número de celular do cliente com base no login
    $clienteQuery = "SELECT nome, celular FROM sis_cliente WHERE login = ?";
    $clienteStmt = $con->prepare($clienteQuery);
    $clienteStmt->bind_param('s', $login);
    $clienteStmt->execute();
    $clienteResult = $clienteStmt->get_result();
    $celular = "";
    $nome = "";

    if ($clienteRow = $clienteResult->fetch_assoc()) {
        $nome = $clienteRow['nome'];
        $celular = formatarNumero($clienteRow['celular']);
    }

    // Monta a mensagem com o nome do cliente incluído
    $mensagem = "💵 *CONFIRMAÇÃO DE PAGAMENTO*\n\n".
                "👤 *Cliente*: $nome\n".
                "✅ *Pagamento recebido em*: $datapag\n".
                "📅 *Fatura com vencimento em*: $datavenc\n".
                "💰 *Valor da fatura*: R$ $valor\n".
                "💸 *Valor do pagamento*: R$ $valorpag\n\n".               
                "*Atenciosamente, Nome do Seu Provedor Aqui* 🤝\n".
                "••••••••••••••••••••••••••••••••••\n".
                "_Mensagem gerada automaticamente pelo sistema._";

    // Codifica o número e a mensagem para a URL
    $celular = rawurlencode($celular);
    $mensagem = rawurlencode($mensagem);

    // Envia a mensagem via API do WhatsApp e registra no log
    if (enviarMensagemWhatsApp($celular, $mensagem)) {
        // Marca o registro como enviado na tabela brl_pago
        $updateQuery = "UPDATE brl_pago SET envio = 1 WHERE id = ?";
        $updateStmt = $con->prepare($updateQuery);
        $updateStmt->bind_param('i', $row['id']); // "i" indica que o parâmetro é um inteiro
        $updateStmt->execute();

        // Escreve no log o sucesso do envio
        escreverLog("Mensagem enviada com sucesso para $nome ($celular)");
    } else {
        // Escreve no log o erro do envio
        escreverLog("Erro ao enviar mensagem para $nome ($celular)");
    }
}

// Função para enviar a mensagem via API do WhatsApp
function enviarMensagemWhatsApp($celular, $mensagem) {
    global $apiBaseURL;

    // Monta a URL completa
    $url = $apiBaseURL . "$celular/$mensagem";

    // Envia a requisição
    $response = file_get_contents($url);

    // Retorna true se a mensagem foi enviada com sucesso, caso contrário, false
    return $response ? true : false;
}

// Função para formatar o número de celular
function formatarNumero($numero) {
    $numero = preg_replace('/\D/', '', $numero);
    if (strlen($numero) == 10) {
        $numero = '55' . substr($numero, 0, 2) . '9' . substr($numero, 2);
    } elseif (strlen($numero) == 11) {
        $numero = '55' . $numero;
    }
    return $numero;
}

// Função para escrever no arquivo de log
function escreverLog($mensagem) {
    global $logFile;
    $dataHora = date('d/m/Y H:i:s');
    $logMensagem = "[$dataHora] $mensagem" . PHP_EOL;
    file_put_contents($logFile, $logMensagem, FILE_APPEND);
}
?>
