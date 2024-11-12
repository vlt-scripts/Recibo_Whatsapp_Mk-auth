<?php
include('addons.class.php');

// Verifica se o usuário está logado
session_name('mka');
if (!isset($_SESSION)) session_start();
if (!isset($_SESSION['mka_logado']) && !isset($_SESSION['MKA_Logado'])) exit('Acesso negado... <a href="/admin/login.php">Fazer Login</a>');

// Variáveis do Manifesto
$manifestTitle = isset($Manifest->name) ? htmlspecialchars($Manifest->name) : '';
$manifestVersion = isset($Manifest->version) ? htmlspecialchars($Manifest->version) : '';

// Configurações do banco de dados
$host = "localhost";
$usuario = "root";
$senha = "vertrigo";
$db = "mkradius";
$conn = new mysqli($host, $usuario, $senha, $db);
if ($conn->connect_error) {
    die("<script>alert('Falha na conexão: " . $conn->connect_error . "');</script>");
}

// Estrutura exata esperada para a tabela brl_pago
$estrutura_esperada = [
    'id' => 'INT(11) NOT NULL',
    'data' => 'TIMESTAMP NOT NULL',
    'login' => 'VARCHAR(64) NOT NULL',
    'coletor' => 'VARCHAR(64)',
    'datavenc' => 'DATE',
    'datapag' => 'DATETIME',
    'valor' => 'DECIMAL(10,2)',
    'valorpag' => 'DECIMAL(10,2)',
    'formapag' => 'VARCHAR(32)',
    'envio' => 'TINYINT(1) NOT NULL DEFAULT 0'
];

// Inicia uma transação para garantir integridade
$conn->begin_transaction();

// Verifica a estrutura da tabela `brl_pago`
$tabela_existe = $conn->query("SHOW TABLES LIKE 'brl_pago'");
if ($tabela_existe->num_rows == 0) {
    // Cria a tabela se ela não existir
    $sql_criar_tabela = "
    CREATE TABLE brl_pago (
        id INT(11) NOT NULL,
        data TIMESTAMP NOT NULL,
        login VARCHAR(64) NOT NULL,
        coletor VARCHAR(64),
        datavenc DATE,
        datapag DATETIME,
        valor DECIMAL(10, 2),
        valorpag DECIMAL(10, 2),
        formapag VARCHAR(32),
        envio TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id)
    )";
    if ($conn->query($sql_criar_tabela) === TRUE) {
        echo "<script>alert('Tabela brl_pago criada com sucesso!');</script>";
    } else {
        echo "<script>alert('Erro ao criar a tabela brl_pago: " . $conn->error . "');</script>";
    }
} else {
    // Verifica cada coluna e a ajusta para que coincida exatamente com a estrutura esperada
    $resultado = $conn->query("SHOW COLUMNS FROM brl_pago");
    $colunas_existentes = [];
    while ($coluna = $resultado->fetch_assoc()) {
        $colunas_existentes[$coluna['Field']] = $coluna['Type'] . ($coluna['Null'] === 'NO' ? ' NOT NULL' : '');
    }

    // Adiciona colunas que faltam e remove as que não estão no esperado
    foreach ($estrutura_esperada as $coluna => $tipo) {
        if (!array_key_exists($coluna, $colunas_existentes)) {
            // Adiciona a coluna ausente
            $sql_alter = "ALTER TABLE brl_pago ADD COLUMN $coluna $tipo";
            if ($conn->query($sql_alter) === TRUE) {
                echo "<script>alert('Coluna $coluna adicionada com sucesso à tabela brl_pago!');</script>";
            } else {
                echo "<script>alert('Erro ao adicionar a coluna $coluna: " . $conn->error . "');</script>";
                $conn->rollback();
                exit;
            }
        }
    }

    // Remove colunas extras
    foreach ($colunas_existentes as $coluna => $tipo) {
        if (!array_key_exists($coluna, $estrutura_esperada)) {
            $sql_alter = "ALTER TABLE brl_pago DROP COLUMN $coluna";
            if ($conn->query($sql_alter) === TRUE) {
                echo "<script>alert('Coluna extra $coluna removida da tabela brl_pago!');</script>";
            } else {
                echo "<script>alert('Erro ao remover a coluna extra $coluna: " . $conn->error . "');</script>";
                $conn->rollback();
                exit;
            }
        }
    }
}

// Confirma a transação
$conn->commit();

// Código SQL exato esperado para a ação do trigger
$trigger_nome = 'tig_brl_pag';
$trigger_sql_esperado = "
BEGIN
    IF NEW.status = 'pago' 
       AND EXISTS (
           SELECT 1 
           FROM sis_cliente 
           WHERE login = NEW.login 
             AND zap <> 'nao' 
             AND cli_ativado <> 'n'
       ) THEN
        INSERT INTO brl_pago (id, data, login, coletor, datavenc, datapag, valor, valorpag, formapag)
        VALUES (NEW.id, NOW(), NEW.login, NEW.coletor, NEW.datavenc, NEW.datapag, NEW.valor, NEW.valorpag, NEW.formapag);
    END IF;
END";

// Verifica se o trigger existe e está correto
$trigger_existe = $conn->query("SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '$db' AND TRIGGER_NAME = '$trigger_nome'");
$recriar_trigger = true;

if ($trigger_existe->num_rows > 0) {
    $trigger_atual = $trigger_existe->fetch_assoc()['ACTION_STATEMENT'];

    // Comparação exata entre o código atual e o esperado
    if (trim($trigger_atual) === trim($trigger_sql_esperado)) {
        $recriar_trigger = false;
    }
}

// Recria o trigger apenas se necessário
if ($recriar_trigger) {
    $conn->query("DROP TRIGGER IF EXISTS $trigger_nome");
    $sql_criar_trigger = "
    CREATE TRIGGER $trigger_nome
    AFTER UPDATE ON sis_lanc
    FOR EACH ROW
    $trigger_sql_esperado";
    if ($conn->query($sql_criar_trigger) === TRUE) {
        echo "<script>alert('Trigger $trigger_nome criada/atualizada com sucesso!');</script>";
    } else {
        echo "<script>alert('Erro ao criar/atualizar o trigger $trigger_nome: " . $conn->error . "');</script>";
    }
}

// Caminho para o arquivo temporário do cron
$cronFilePath = '/tmp/cron_recibo_whatsapp';

// Função para atualizar o cron com o intervalo especificado
function atualizarCron($intervaloMinutos) {
    global $cronFilePath;
    $comando = "/usr/bin/php -q /opt/mk-auth/admin/addons/Recibo_Whatsapp/enviozap.php >/dev/null 2>&1";
    $cronLinha = "*/$intervaloMinutos * * * * $comando" . PHP_EOL;
    file_put_contents($cronFilePath, $cronLinha);
    exec("crontab $cronFilePath");
}

// Função para exibir o agendamento atual
function obterAgendamentoAtual() {
    $output = shell_exec("crontab -l");
    return $output ? htmlspecialchars($output) : "<span class='no-schedule'>Nenhum agendamento configurado</span>";
}

// Função para excluir apenas o agendamento específico
function excluirAgendamentoEspecifico() {
    $output = shell_exec("crontab -l | grep -v '/usr/bin/php -q /opt/mk-auth/admin/addons/Recibo_Whatsapp/enviozap.php >/dev/null 2>&1' | crontab -");
    if ($output === null) {
        echo '<script>alert("Agendamento excluído com sucesso.");</script>';
    } else {
        echo '<script>alert("Erro ao excluir o agendamento.");</script>';
    }
}

// Verifica se o formulário de intervalo foi enviado
if (isset($_POST['intervalo_minutos'])) {
    $intervaloMinutos = (int)$_POST['intervalo_minutos'];
    atualizarCron($intervaloMinutos);
    echo "<script>
        alert('Agendamento atualizado para cada $intervaloMinutos minutos!');
        window.location.href = window.location.href; // Redireciona para a mesma página para limpar o POST
    </script>";
    exit;
}

// Verifica se o formulário de exclusão foi enviado
if (isset($_POST['delete_schedule'])) {
    excluirAgendamentoEspecifico();
    echo '<script>
        window.location.href = window.location.href; // Redireciona para a mesma página
    </script>';
    exit;
}

// Caminho e permissões para o diretório de configurações
$dir_path = '/opt/mk-auth/dados/Recibo_Whatsapp';
$file_path = $dir_path . '/config.php';
if (!is_dir($dir_path)) mkdir($dir_path, 0755, true);

// Define a chave de criptografia
$chave_criptografia = '3NyBm8aa54eg8jeE';

// Funções de encriptação e desencriptação de dados
function encriptar($dados, $chave) {
    return openssl_encrypt($dados, 'aes-256-cbc', $chave, 0, str_repeat('0', 16));
}

function desencriptar($dados, $chave) {
    return openssl_decrypt($dados, 'aes-256-cbc', $chave, 0, str_repeat('0', 16));
}

// Verifica e cria o arquivo de configuração criptografado, se necessário
if (!file_exists($file_path)) {
    $config_content = '<?php return ' . var_export(['ip' => '', 'user' => '', 'token' => ''], true) . ';';
    file_put_contents($file_path, $config_content);
    chmod($file_path, 0600);  // Permissões restritas
}

// Lê e desencripta as configurações do arquivo
$configuracoes = include($file_path);
$ip = isset($configuracoes['ip']) ? desencriptar($configuracoes['ip'], $chave_criptografia) : '';
$user = isset($configuracoes['user']) ? desencriptar($configuracoes['user'], $chave_criptografia) : '';
$token = isset($configuracoes['token']) ? desencriptar($configuracoes['token'], $chave_criptografia) : '';

// Salva o token, IP e user encriptados no arquivo, se o formulário foi enviado
if (isset($_POST['salvar_configuracoes'])) {
    $ip = $_POST['ip'] ?? '';
    $user = $_POST['user'] ?? '';
    $token = $_POST['token'] ?? '';
    $novas_configuracoes = [
        'ip' => encriptar($ip, $chave_criptografia),
        'user' => encriptar($user, $chave_criptografia),
        'token' => encriptar($token, $chave_criptografia),
    ];

    $config_content = '<?php return ' . var_export($novas_configuracoes, true) . ';';
    if (file_put_contents($file_path, $config_content) !== false) {
        chmod($file_path, 0600);  // Define permissão 0600 para o arquivo
        echo "<script>alert('Configurações de Token, IP e User salvas com sucesso!');</script>";
    } else {
        echo "<script>alert('Erro ao salvar as configurações. Verifique as permissões do diretório.');</script>";
    }
}
?>


<!DOCTYPE html>
<html lang="pt-BR" class="has-navbar-fixed-top">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="iso-8859-1">
<title>MK-AUTH :: <?php echo $manifestTitle; ?></title>

<link href="../../estilos/mk-auth.css" rel="stylesheet" type="text/css" />
<link href="../../estilos/font-awesome.css" rel="stylesheet" type="text/css" />
<link href="../../estilos/bi-icons.css" rel="stylesheet" type="text/css" />

<script src="../../scripts/jquery.js"></script>
<script src="../../scripts/mk-auth.js"></script>

<style>
    .container {
        max-width: 1300px; /* Aumenta a largura máxima do contêiner */
        width: 100%; /* Define a largura para 90% da tela, adaptando-se a telas menores */
        margin: 20px auto; /* Centraliza o contêiner */
        background: #fff;
        padding: 30px; /* Ajusta o padding para um espaçamento interno confortável */
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
        border-radius: 8px; /* Deixa os cantos ligeiramente arredondados */
    }
    h2 {
        text-align: center;
        color: #333;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin: 20px 0;
    }
    table, th, td {
        border: 1px solid #ddd;
    }
    th, td {
        padding: 5px;
        text-align: center;
    }
    th {
        background-color: #4CAF50;
        color: white;
    }
    tr:nth-child(even) {
        background-color: #f2f2f2;
    }
    tr:hover {
        background-color: #ddd;
    }
    .btn-enviar-zap, .btn-limpar-log {
        background-color: #4CAF50;
        color: white;
        padding: 12px 20px;
        font-size: 16px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        transition: background-color 0.3s ease;
    }
    .btn-enviar-zap:hover, .btn-limpar-log:hover {
        background-color: #45a049;
    }
    .btn-limpar-log {
        background-color: red;
    }
    .log-container {
        background-color: #f9f9f9;
        padding: 20px;
        border: 1px solid #ddd;
        border-radius: 5px;
        max-height: 300px;
        overflow-y: scroll;
        color: blue;
    }
    /* Estilos para o Modal */
    #modalConfirmacao {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
    }
    #modalConfirmacao .modal-content {
        background-color: #fff;
        padding: 20px;
        border-radius: 8px;
        max-width: 300px;
        margin: 100px auto;
        text-align: center;
    }
    #modalConfirmacao button {
        background-color: #4CAF50;
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
    }
	    .pagination {
        display: flex;
        justify-content: center;
        margin: 20px 0;
        list-style: none;
        padding: 0;
    }

        .pagination li {
        margin: 0 5px;
    }

       .pagination a,
       .pagination strong {
        display: inline-block;
        padding: 8px 12px;
        text-decoration: none;
        border-radius: 5px;
        color: #333;
        background-color: #f2f2f2;
        border: 1px solid #ddd;
        transition: background-color 0.3s ease;
    }

        .pagination a:hover {
         background-color: #ddd;
    }

        .pagination strong {
         color: white;
         background-color: #4CAF50; /* Destaque para a página atual */
         font-weight: bold;
}
</style>

</head>
<body>

<?php include('../../topo.php'); ?>

<nav class="breadcrumb has-bullet-separator is-centered" aria-label="breadcrumbs">
    <ul>
        <li><a href="#"> ADDON</a></li>
        <a href="#" aria-current="page"> <?= $manifestTitle . " - V " . $manifestVersion; ?> </a>
    </ul>
</nav>

<!-- Botão para exibir o formulário de configurações -->
<div class="container">
    <div style="display: flex; align-items: center; justify-content: space-between;">
        <h2>Configurações de Token e IP</h2>
        <div style="display: flex; gap: 10px;">
            <button id="mostrarHorario" class="toggle-button" onclick="toggleSection('agendamentoForm')" style="background: none; border: none; cursor: pointer;">
                <img src="icon_agen.png" alt="Mostrar Horário" style="width: 30px; height: 30px;">
            </button>
            <button id="mostrarConfiguracoes" onclick="toggleSection('configForm')" style="background: none; border: none; cursor: pointer;">
                <img src="icon_config.png" alt="Mostrar Configurações" style="width: 30px; height: 30px;">
            </button>
        </div>
    </div>

    <!-- Formulário de Configurações (oculto inicialmente) -->
    <div id="configForm" style="margin-top: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background-color: #f9f9f9; display: none;">
        
        <!-- Tutorial Estilizado -->
        <div style="background-color: #f0f8ff; padding: 20px; border-left: 6px solid #5a9bd4; margin-bottom: 20px; border-radius: 5px;">
            <h3 style="display: flex; align-items: center; font-weight: bold; color: #31708f;">
                <img src="icon_config.png" alt="Info" style="width: 24px; height: 24px; margin-right: 8px;"> Tutorial de Configuração
            </h3>
            <p style="margin-top: 10px; color: #555;">
                Utilize as informações abaixo como exemplo para configurar o envio de mensagens com a API.
            </p>
            <ul style="list-style: none; padding: 0; margin-top: 15px;">
                <li style="margin-bottom: 8px;">
                    <strong style="color: #31708f;">SERVER:</strong> <span style="color: #555;">192.168.3.250:8000</span> <em style="color: #888;">(Exemplo)</em>
                </li>
                <li style="margin-bottom: 8px;">
                    <strong style="color: #31708f;">USER:</strong> <span style="color: #555;">admin</span> <em style="color: #888;">(Exemplo)</em>
                </li>
                <li style="margin-bottom: 8px;">
                    <strong style="color: #31708f;">Token:</strong> <span style="color: #555;">admin</span> <em style="color: #888;">(Exemplo)</em>
                </li>
            </ul>
            <p style="margin-top: 15px; color: #555;">
                Insira os dados fornecidos pela API para configurar corretamente o envio de mensagens.
            </p>
        </div>

        <!-- Formulário de Configurações -->
        <form method="post">
            <!-- Campo SERVER -->
            <label for="ip" style="font-weight: bold;">SERVER:</label>
            <input type="text" id="ip" name="ip" value="<?php echo htmlspecialchars($ip); ?>" style="width: 100%; padding: 8px; margin: 5px 0 15px; border: 1px solid #ccc; border-radius: 5px;">

            <!-- Campo User -->
            <label for="user" style="font-weight: bold;">User:</label>
            <input type="text" id="user" name="user" value="<?php echo htmlspecialchars($user); ?>" style="width: 100%; padding: 8px; margin: 5px 0 15px; border: 1px solid #ccc; border-radius: 5px;">

            <!-- Campo Token -->
            <label for="token" style="font-weight: bold;">Token:</label>
            <div style="position: relative;">
                <input type="password" id="token" name="token" value="<?php echo htmlspecialchars($token); ?>" style="width: 100%; padding: 8px; margin: 5px 0 15px; border: 1px solid #ccc; border-radius: 5px;">
                <label style="display: flex; align-items: center; margin-top: 5px;">
                    <input type="checkbox" onclick="togglePasswordVisibility()" style="margin-right: 5px;">
                    Mostrar Token
                </label>
            </div>

            <!-- Botão Salvar Configurações -->
            <button type="submit" name="salvar_configuracoes" style="background-color: #4CAF50; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; transition: background-color 0.3s ease;">
                Salvar Configurações
            </button>
        </form>
    </div>

    <!-- Formulário de Agendamento -->
    <div id="agendamentoForm" class="config-section" style="display: none; max-width: 1000px; margin: 20px auto; padding: 25px; border-radius: 15px; background: #ffffff; box-shadow: 0px 8px 20px rgba(0, 0, 0, 0.15);">
        
        <h3 style="text-align: center; color: #4CAF50; font-size: 1.5em; font-weight: bold; margin-bottom: 20px;">Agendamento</h3>

        <div style="display: flex; justify-content: center; gap: 15px; margin-bottom: 20px;">
            <button form="scheduleForm" type="submit" style="display: flex; align-items: center; gap: 8px; padding: 10px 18px; font-size: 1em; font-weight: bold; color: white; background-color: #4CAF50; border: none; border-radius: 50px; cursor: pointer; transition: all 0.3s ease;">
                <i class="fa fa-save"></i> Salvar
            </button>
            
            <form method="post" style="margin: 0;">
                <input type="hidden" name="delete_schedule" value="1">
                <button type="submit" style="display: flex; align-items: center; gap: 8px; padding: 10px 18px; background-color: #e74c3c; color: white; font-size: 1em; font-weight: bold; border: none; border-radius: 50px; cursor: pointer; transition: all 0.3s ease;" onclick="return confirm('Tem certeza que deseja excluir o agendamento específico?');">
                    <i class="fa fa-trash"></i> Excluir
                </button>
            </form>
        </div>

        <form id="scheduleForm" method="post" style="display: flex; flex-direction: column; gap: 15px;">
            <label for="intervalo_minutos" style="font-size: 1.1em; color: #333; font-weight: 600;">Intervalo (min):</label>
            <input type="number" id="intervalo_minutos" name="intervalo_minutos" min="1" max="60" required placeholder="Minutos" style="padding: 12px; font-size: 1.1em; border: 1px solid #ddd; border-radius: 8px; background-color: #f8f8f8;">
        </form>

        <div class="cron-display" style="margin-top: 20px; padding: 15px; text-align: center; border-radius: 8px; background-color: #f7f9fb; border: 1px solid #ddd;">
            <strong style="color: #4CAF50; font-size: 1.1em;">Agendamento Atual:</strong><br>
            <?php echo obterAgendamentoAtual(); ?>
        </div>
    </div>
</div>

<script>
    function toggleSection(sectionId) {
        const section = document.getElementById(sectionId);
        section.style.display = section.style.display === 'none' ? 'block' : 'none';
    }

    function togglePasswordVisibility() {
        const tokenInput = document.getElementById('token');
        tokenInput.type = tokenInput.type === 'password' ? 'text' : 'password';
    }
</script>


<div class="container">
    <h2>Registros da Tabela</h2>

<?php
// Defina o número de resultados por página
$resultados_por_pagina = 10;

// Verifique se uma página específica foi solicitada, caso contrário, inicie na página 1
$pagina_atual = isset($_GET['pagina']) && is_numeric($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$inicio = ($pagina_atual - 1) * $resultados_por_pagina;

// Obtenha o número total de registros
$total_registros = $conn->query("SELECT COUNT(*) as total FROM brl_pago")->fetch_assoc()['total'];
$total_paginas = ceil($total_registros / $resultados_por_pagina);

// Consulta com limite e offset para paginação
$sql = "SELECT id, data, login, coletor, datavenc, datapag, valor, valorpag, formapag, envio 
        FROM brl_pago ORDER BY data DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $resultados_por_pagina, $inicio);

if ($stmt) {
    // Executa a consulta preparada
    $stmt->execute();
    $stmt->bind_result($id, $data, $login, $coletor, $datavenc, $datapag, $valor, $valorpag, $formapag, $envio);

    // Começa a renderizar a tabela
    echo "<table>
            <tr>
                <th>ID</th>
                <th>Data</th>
                <th>Login</th>
                <th>Coletor</th>
                <th>Data Pagamento</th>
				<th>Data Vencimento</th>
                <th>Valor</th>
                <th>Valor Pago</th>
                <th>Forma de Pagamento</th>
                <th>Envio</th>
            </tr>";

    // Define um contador de linha
    $linha = 0;

    // Loop pelos resultados
    while ($stmt->fetch()) {
        $envioStatus = $envio == 1 
            ? "<span style='color: green; font-weight: bold;'>Sim</span>" 
            : "<span style='color: red; font-weight: bold;'>Não</span>";
        
        // Formata a data no formato preferido "01-11-2024 12:46:53"
        $dataFormatada = (new DateTime($data))->format('d-m-Y H:i:s');
		$datavencFormatada = (new DateTime($datavenc))->format('d-m-Y');
		$datapagFormatada = (new DateTime($datapag))->format('d-m-Y H:i:s');
        
        // Alterna a cor do texto entre verde escuro e azul escuro e adiciona negrito
        $textoCor = ($linha % 2 == 0) ? "color: #556B2F; font-weight: bold;" : "color: #4682B4; font-weight: bold;";
        
        echo "<tr style='$textoCor'>
                <td>" . htmlspecialchars($id) . "</td>
                <td>" . htmlspecialchars($dataFormatada) . "</td>
                <td>" . htmlspecialchars($login) . "</td>
                <td>" . htmlspecialchars($coletor) . "</td>
                <td>" . htmlspecialchars($datapagFormatada) . "</td>
                <td>" . htmlspecialchars($datavencFormatada) . "</td>
                <td>" . htmlspecialchars($valor) . "</td>
                <td>" . htmlspecialchars($valorpag) . "</td>
                <td>" . htmlspecialchars($formapag) . "</td>
                <td>" . $envioStatus . "</td>
              </tr>";
        
        // Incrementa o contador de linha
        $linha++;
    }
    echo "</table>";

    // Fecha o stmt após o uso
    $stmt->close();
} else {
    echo "<div style='text-align: center; margin: 20px 0; font-size: 18px; color: #333;'>
            <p>Erro ao preparar a consulta para exibir os registros.</p>
          </div>";
}

// Exibição dos links de paginação
echo '<ul class="pagination">';
for ($pagina = 1; $pagina <= $total_paginas; $pagina++) {
    if ($pagina == $pagina_atual) {
        echo "<li><strong>$pagina</strong></li>";
    } else {
        echo "<li><a href='?pagina=$pagina'>$pagina</a></li>";
    }
}
echo '</ul>';

// Fecha a conexão ao final de todas as operações
$conn->close();
?>

</div>

<div class="container">
    <form id="envioForm" style="display: flex; justify-content: center; gap: 15px; margin-top: 20px;">
        <!-- Botão Enviar via WhatsApp -->
        <button type="button" class="btn-enviar-zap" onclick="enviarRecibo()" style="padding: 10px 18px; font-size: 1em; font-weight: bold; color: white; background-color: #4CAF50; border: none; border-radius: 8px; cursor: pointer; transition: background-color 0.3s ease;">
            Enviar via WhatsApp
        </button>
        
        <!-- Botão Limpar Log -->
        <button type="submit" formaction="limpar_log.php" method="post" onclick="return confirm('Tem certeza que deseja limpar o log?');" class="btn-limpar-log" style="padding: 10px 18px; background-color: #e74c3c; color: white; font-size: 1em; font-weight: bold; border: none; border-radius: 8px; cursor: pointer; transition: background-color 0.3s ease;">
            Limpar Log
        </button>
    </form>
</div>
<div class="container" style="background-color: #f9f9f9; padding: 20px; border: 1px solid #ddd; border-radius: 5px; max-height: 300px; overflow-y: scroll; color: blue;">
    <pre><?php
        $logFile = '/opt/mk-auth/dados/Recibo_Whatsapp/log_pagamentos.txt';
        if (file_exists($logFile)) {
            // Lê o conteúdo do arquivo e o divide em linhas
            $logContent = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            // Inverte a ordem das linhas para que as mais recentes fiquem no topo
            $logContent = array_reverse($logContent);
            
            // Formata cada linha do log
            foreach ($logContent as &$line) {
                if (strpos($line, 'Mensagem enviada com sucesso') !== false) {
                    // Adiciona a cor verde e estilo bold para as mensagens enviadas com sucesso
                    $line = "<span style='color: green; font-weight: bold;'>" . htmlspecialchars($line) . "</span>";
                    
                    // Adiciona cor darkcyan para a data, azul para o nome do cliente, e darkslateblue para o número de telefone no formato desejado
                    $line = preg_replace(
                        "/\[(\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2})\] Mensagem enviada com sucesso para (.+?) \((\d{2})(\d{2})(\d{5})(\d{4})\)/",
                        "[<span style='color: darkcyan; font-weight: bold;'>$1</span>] Mensagem enviada com sucesso para <span style='color: blue; font-weight: bold;'>$2</span> (+$3 $4 $5-$6)",
                        $line
                    );
                } elseif (strpos($line, 'Erro ao enviar mensagem') !== false) {
                    // Adiciona a cor vermelha e estilo bold para mensagens de erro
                    $line = "<span style='color: red; font-weight: bold;'>" . htmlspecialchars($line) . "</span>";
                } else {
                    // Coloca todas as outras linhas em bold
                    $line = "<strong>" . htmlspecialchars($line) . "</strong>";
                }
            }
            
            // Exibe o conteúdo formatado
            echo implode("\n", $logContent);
        } else {
            echo "<strong>Arquivo de log não encontrado.</strong>";
        }
    ?></pre>
</div>

<!-- Modal de Confirmação --> 
<div id="modalConfirmacao" style="display: none;">
    <div class="modal-content">
        <p>Recibo enviado com sucesso!</p>
        <button onclick="fecharModal()">OK</button>
    </div>
</div>

<?php include('../../baixo.php'); ?>

<script src="../../menu.js.hhvm"></script>
<script>
    function enviarRecibo() {
        $.post("enviozap.php", $('#envioForm').serialize())
            .done(function(response) {
                // Exibe o modal de confirmação em caso de sucesso
                $('#modalConfirmacao').fadeIn();
            })
            .fail(function() {
                // Também exibe o modal de confirmação em caso de erro
                $('#modalConfirmacao').fadeIn();
            });
    }

    function fecharModal() {
        $('#modalConfirmacao').fadeOut(function() {
            // Recarrega a página após o modal ser fechado
            location.reload();
        });
    }
</script>

</body>
</html>
