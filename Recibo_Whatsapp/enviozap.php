<?php
// Define a chave de criptografia (deve ser a mesma usada no arquivo de configuração)
$chave_criptografia = '3NyBm8aa54eg8jeE';

// Função para desencriptar os dados
function desencriptar($dados, $chave) {
    return openssl_decrypt($dados, 'aes-256-cbc', $chave, 0, str_repeat('0', 16));
}

// Carrega e desencripta configurações de token e IP
$configFile = '/opt/mk-auth/dados/Recibo_Whatsapp/config.php';
if (file_exists($configFile)) {
    $config = include($configFile);
    $ip = desencriptar($config['ip'], $chave_criptografia);
    $user = desencriptar($config['user'], $chave_criptografia);
	$token = desencriptar($config['token'], $chave_criptografia);

    if ($token && $ip) {
        $apiBaseURL = "http://$ip/send-message"; // URL do PlaySMS
    } else {
        die("Erro: Falha ao desencriptar o token ou IP.");
    }
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

if ($stmt) {
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
        $clienteQuery = "SELECT nome, celular, cpf_cnpj FROM sis_cliente WHERE login = ?";
        $clienteStmt = $con->prepare($clienteQuery);
        
        if ($clienteStmt) {
            $clienteStmt->bind_param('s', $login);
            $clienteStmt->execute();
            $clienteResult = $clienteStmt->get_result();
            $celular = "";
            $nome = "";
			$cpfCnpj = "";

            if ($clienteRow = $clienteResult->fetch_assoc()) {
                $nome = $clienteRow['nome'];
                $celular = formatarNumero($clienteRow['celular']);
				
		    // Verifica se é CPF (11 dígitos) ou CNPJ (14 dígitos) e aplica a formatação apropriada
            $cpfCnpj = preg_replace(
                strlen($clienteRow['cpf_cnpj']) === 11 
                ? "/(\d{3})(\d{3})(\d{3})(\d{2})/" 
                : "/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/", 
                strlen($clienteRow['cpf_cnpj']) === 11 
                ? '$1.$2.$3-$4' 
                : '$1.$2.$3/$4-$5', 
            $clienteRow['cpf_cnpj']
        );
    }
            
          // Define a mensagem com o texto e emojis
          $mensagem = "💵 *CONFIRMAÇÃO DE PAGAMENTO*\n\n".                   
                      "👤 *Cliente*: $nome\n".
					  "📄 *ID do Pagamento*: $row[id]\n". // Adiciona o ID do pagamento
                      "📑 *CPF/CNPJ*: $cpfCnpj\n".
                      "✅ *Pagamento recebido em*: $datapag\n".
                      "📅 *Fatura com vencimento em*: $datavenc\n".
                      "💰 *Valor da fatura*: R$ $valor\n".
                      "💸 *Valor do pagamento*: R$ $valorpag\n".       
                      "👤 *Pagamento recebido por*: $coletor\n".    
                      "💳 *Forma de pagamento*: $formapag\n\n".                        
                      "*Atenciosamente, Nome do Seu Provedor Aqui* 🤝\n".
                      "••••••••••••••••••••••••••••••••••\n".
                      "_Mensagem gerada automaticamente pelo sistema._";


            // Verifica o número de celular antes de enviar
            if ($celular && strlen($celular) >= 12) {
                if (enviarMensagemPlaySMS($celular, $mensagem)) {
                    // Marca o registro como enviado na tabela brl_pago
                    $updateQuery = "UPDATE brl_pago SET envio = 1 WHERE id = ?";
                    $updateStmt = $con->prepare($updateQuery);
                    if ($updateStmt) {
                        $updateStmt->bind_param('i', $row['id']);
                        $updateStmt->execute();
                        $updateStmt->close();
                    }

                    // Escreve no log o sucesso do envio
                    escreverLog("Mensagem enviada com sucesso para $nome ($celular)");
                } else {
                    // Escreve no log o erro do envio
                    escreverLog("Erro ao enviar mensagem para $nome ($celular)");
                }
            } else {
                // Loga um erro se o número for inválido
                escreverLog("Número de telefone inválido para $nome");
            }

            $clienteStmt->close();
        } else {
            escreverLog("Erro ao preparar a consulta para cliente: " . $con->error);
        }
    }
    
    $stmt->close();
} else {
    escreverLog("Erro ao preparar a consulta: " . $con->error);
}

// Fecha a conexão ao final de todas as operações
$con->close();

// Função para enviar a mensagem via PlaySMS
function enviarMensagemPlaySMS($celular, $mensagem) {
    global $apiBaseURL, $token;

    $postData = http_build_query([
        'u' => $user,
        'p' => $token,           // Usa o token desencriptado
        'to' => $celular,
        'msg' => $mensagem,
        'token' => 'admin',
        'celular' => "+$celular",
        'mensagem' => $mensagem
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiBaseURL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $errorMsg = curl_error($ch);
        escreverLog("Erro de conexão com API para $celular: " . $errorMsg);
        curl_close($ch);
        return false;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        return true;
    } else {
        escreverLog("Erro de envio para $celular: código HTTP $httpCode. Resposta: $response");
        return false;
    }
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
