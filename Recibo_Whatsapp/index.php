<?php
include('addons.class.php');

// VERIFICA SE O USUÁRIO ESTÁ LOGADO --------------------------------------------------------------
session_name('mka');
if (!isset($_SESSION)) session_start();
if (!isset($_SESSION['mka_logado']) && !isset($_SESSION['MKA_Logado'])) exit('Acesso negado... <a href="/admin/login.php">Fazer Login</a>');
// VERIFICA SE O USUÁRIO ESTÁ LOGADO --------------------------------------------------------------

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

// Verificar e criar a tabela brl_pago, se necessário
$tabela_existe = $conn->query("SHOW TABLES LIKE 'brl_pago'");
if ($tabela_existe->num_rows == 0) {
    $tabela_sql = "CREATE TABLE IF NOT EXISTS brl_pago (
    id INT(11) NOT NULL,
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
    if ($conn->query($tabela_sql) === TRUE) {
        echo "<script>alert('Tabela brl_pago criada com sucesso!');</script>";
    } else {
        echo "<script>alert('Erro ao criar a tabela: " . $conn->error . "');</script>";
    }
}

// Verificar e criar a trigger tig_brl_pag, se necessário
$trigger_existe = $conn->query("SHOW TRIGGERS LIKE 'tig_brl_pag'");
if ($trigger_existe->num_rows == 0) {
    $trigger_sql = "
    CREATE TRIGGER tig_brl_pag
    AFTER UPDATE ON sis_lanc
    FOR EACH ROW
    BEGIN
        IF NEW.status = 'pago' THEN
            IF (SELECT zap FROM sis_cliente WHERE login = NEW.login) <> 'nao'
               AND (SELECT cli_ativado FROM sis_cliente WHERE login = NEW.login) <> 'n' THEN
                INSERT INTO brl_pago (id, login, coletor, datavenc, datapag, valor, valorpag, formapag)
                VALUES (NEW.id, NEW.login, NEW.coletor, NEW.datavenc, NEW.datapag, NEW.valor, NEW.valorpag, NEW.formapag);
            END IF;
        END IF;
    END
    ;";
    if ($conn->query($trigger_sql) === TRUE) {
        echo "<script>alert('Trigger tig_brl_pag criada com sucesso!');</script>";
    } else {
        echo "<script>alert('Erro ao criar a trigger: " . $conn->error . "');</script>";
    }
}

// Caminho para o arquivo temporário do cron
$cronFilePath = '/tmp/cron_recibo_whatsapp';

// Função para atualizar o cron com o intervalo especificado
function atualizarCron($intervaloMinutos) {
    global $cronFilePath;
    $comando = "/usr/bin/php /opt/mk-auth/admin/addons/Recibo_Whatsapp/enviozap.php";
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
    $output = shell_exec("crontab -l | grep -v '/opt/mk-auth/admin/addons/Recibo_Whatsapp/enviozap.php' | crontab -");
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
    echo "<script>alert('Agendamento atualizado para cada $intervaloMinutos minutos!');</script>";
}

// Verifica se o formulário de exclusão foi enviado
if (isset($_POST['delete_schedule'])) {
    excluirAgendamentoEspecifico();
}

// Configuração de diretório e arquivo
$dir_path = '/opt/mk-auth/dados/Recibo_Whatsapp';
$file_path = $dir_path . '/config.php';

// Cria o diretório se não existir
if (!is_dir($dir_path)) {
    mkdir($dir_path, 0755, true);
}

// Verifica e cria o arquivo de configuração, se necessário
if (!file_exists($file_path)) {
    $config_content = '<?php return ' . var_export(['token' => '', 'ip' => ''], true) . ';';
    file_put_contents($file_path, $config_content);
}

// Lê as configurações do arquivo
$configuracoes = include($file_path);
$token = isset($configuracoes['token']) ? $configuracoes['token'] : '';
$ip = isset($configuracoes['ip']) ? $configuracoes['ip'] : '';

// Salva o token e IP se o formulário foi enviado
if (isset($_POST['salvar_configuracoes'])) {
    $token = $_POST['token'] ?? '';
    $ip = $_POST['ip'] ?? '';

    $novas_configuracoes = [
        'token' => $token,
        'ip' => $ip,
    ];

    $config_content = '<?php return ' . var_export($novas_configuracoes, true) . ';';

    if (file_put_contents($file_path, $config_content) !== false) {
        echo "<script>alert('Configurações de Token e IP salvas com sucesso!');</script>";
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
        width: 100%;
        margin: 20px auto;
        background: #fff;
        padding: 20px;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
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
.config-section {
    margin-top: 20px;
    padding: 20px;
    background: #f4f4f9;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    border: 1px solid #e1e1e8;
}

.config-section form {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.config-section label {
    font-weight: 600;
    color: #444;
    margin-bottom: 5px;
}

.config-section input[type="password"],
.config-section input[type="text"],
.config-section input[type="number"] {
    width: 100%;
    padding: 10px;
    font-size: 1em;
    border: 1px solid #ccc;
    border-radius: 5px;
    transition: border-color 0.3s ease, box-shadow 0.3s ease;
}

.config-section input[type="password"]:focus,
.config-section input[type="text"]:focus,
.config-section input[type="number"]:focus {
    border-color: #4CAF50;
    box-shadow: 0 0 5px rgba(76, 175, 80, 0.3);
    outline: none;
}

.config-section button {
    padding: 12px;
    font-size: 1em;
    color: #fff;
    background-color: #4CAF50;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    transition: background-color 0.3s ease, transform 0.2s ease;
}

.config-section button:hover {
    background-color: #45a049;
    transform: scale(1.02);
}

.cron-display {
    margin-top: 15px;
    font-size: 0.9em;
    color: #333;
    padding: 10px;
    background-color: #f7f7f9;
    border-radius: 5px;
    border: 1px solid #ddd;
}

	
</style>

</head>
<body>

<?php include('../../topo.php'); ?>

<nav class="breadcrumb has-bullet-separator is-centered" aria-label="breadcrumbs">
<ul>
<li><a href="#"> ADDON</a></li>
<a href="#" aria-current="page"> <?= $manifestTitle . " - V " . $manifestVersion; ?> </a></ul>
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

    <!-- Formulário de Configurações de Token e IP -->
    <div id="configForm" class="config-section" style="display: none;">
        <form method="post">
        <label for="token">Token:</label>
        <div style="position: relative;">
        <input type="password" id="token" name="token" value="<?php echo htmlspecialchars($token); ?>" 
           style="width: 100%; padding: 8px; font-size: 1em; border: 1px solid #ddd; border-radius: 4px;">

        <label style="display: flex; align-items: center; margin-top: 8px; font-size: 0.9em; color: #555;">
        <input type="checkbox" onclick="togglePasswordVisibility()" style="margin-right: 5px;">
        Mostrar Token
        </label>
    </div>

    <script>
    function togglePasswordVisibility() {
        const tokenInput = document.getElementById('token');
        tokenInput.type = tokenInput.type === 'password' ? 'text' : 'password';
    }
    </script>

            <label for="ip">IP:</label>
            <input type="text" id="ip" name="ip" value="<?php echo htmlspecialchars($ip); ?>">
            <button type="submit" name="salvar_configuracoes">Salvar Configurações</button>
        </form>
    </div>

<!-- Formulário de Agendamento -->
<div id="agendamentoForm" class="config-section" style="display: none; max-width: 1000px; margin: 20px auto; padding: 25px; border-radius: 15px; background: #ffffff; box-shadow: 0px 8px 20px rgba(0, 0, 0, 0.15);">
    
    <h3 style="text-align: center; color: #4CAF50; font-size: 1.5em; font-weight: bold; margin-bottom: 20px;">Agendamento</h3>

    <!-- Botões Salvar e Excluir abaixo do título -->
    <div style="display: flex; justify-content: center; gap: 15px; margin-bottom: 20px;">
        <!-- Botão Salvar -->
        <button form="scheduleForm" type="submit" style="display: flex; align-items: center; gap: 8px; padding: 10px 18px; font-size: 1em; font-weight: bold; color: white; background-color: #4CAF50; border: none; border-radius: 50px; cursor: pointer; transition: all 0.3s ease;">
            <i class="fa fa-save"></i> Salvar
        </button>
        
        <!-- Botão Excluir -->
        <form method="post" style="margin: 0;">
            <input type="hidden" name="delete_schedule" value="1">
            <button type="submit" style="display: flex; align-items: center; gap: 8px; padding: 10px 18px; background-color: #e74c3c; color: white; font-size: 1em; font-weight: bold; border: none; border-radius: 50px; cursor: pointer; transition: all 0.3s ease;" onclick="return confirm('Tem certeza que deseja excluir o agendamento específico?');">
                <i class="fa fa-trash"></i> Excluir
            </button>
        </form>
    </div>
    
    <!-- Formulário para definir o intervalo -->
    <form id="scheduleForm" method="post" style="display: flex; flex-direction: column; gap: 15px;">
        <label for="intervalo_minutos" style="font-size: 1.1em; color: #333; font-weight: 600;">Intervalo (min):</label>
        <input type="number" id="intervalo_minutos" name="intervalo_minutos" min="1" max="60" required placeholder="Minutos" 
               style="padding: 12px; font-size: 1.1em; border: 1px solid #ddd; border-radius: 8px; background-color: #f8f8f8;">
    </form>

    <!-- Exibição do agendamento atual -->
    <div class="cron-display" style="margin-top: 20px; padding: 15px; text-align: center; border-radius: 8px; background-color: #f7f9fb; border: 1px solid #ddd;">
        <strong style="color: #4CAF50; font-size: 1.1em;">Agendamento Atual:</strong><br>
        <?php echo obterAgendamentoAtual(); ?>
    </div>
</div>

<script>
    function toggleSection(sectionId) {
        const section = document.getElementById(sectionId);
        section.style.display = section.style.display === 'none' ? 'block' : 'none';
    }
</script>

<div class="container">
    <h2>Registros da Tabela</h2>

    <?php
    // Exibir o conteúdo da tabela brl_pago
    $sql = "SELECT * FROM brl_pago ORDER BY id DESC";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        echo "<table>
                <tr>
                    <th>ID</th>
                    <th>Login</th>
                    <th>Coletor</th>
                    <th>Data Vencimento</th>
                    <th>Data Pagamento</th>
                    <th>Valor</th>
                    <th>Valor Pago</th>
                    <th>Forma de Pagamento</th>
					<th>Envio</th>
                </tr>";
    while($row = $result->fetch_assoc()) {
        // Define o estilo para OK (verde) e Fail (vermelho)
        $envioStatus = $row["envio"] == 1 
            ? "<span style='color: green; font-weight: bold;'>OK</span>" 
            : "<span style='color: red; font-weight: bold;'>Fail</span>";
        
        echo "<tr>
                <td>" . $row["id"]. "</td>
                <td>" . $row["login"]. "</td>
                <td>" . $row["coletor"]. "</td>
                <td>" . $row["datavenc"]. "</td>
                <td>" . $row["datapag"]. "</td>
                <td>" . $row["valor"]. "</td>
                <td>" . $row["valorpag"]. "</td>
                <td>" . $row["formapag"]. "</td>
                <td>" . $envioStatus . "</td>
              </tr>";
    }
        echo "</table>";
    } else {
        echo "<div style='text-align: center; margin: 20px 0; font-size: 18px; color: #333;'>
                <p>Nenhum registro encontrado.</p>
              </div>";
    }
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

<div class="log-container">
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
                    // Adiciona a cor verde para as mensagens enviadas com sucesso
                    $line = "<span style='color: green;'>" . htmlspecialchars($line) . "</span>";
                } elseif (strpos($line, 'Erro ao enviar mensagem') !== false) {
                    // Adiciona a cor vermelha para mensagens de erro
                    $line = "<span style='color: red;'>" . htmlspecialchars($line) . "</span>";
                } else {
                    // Escapa outras linhas sem alterar
                    $line = htmlspecialchars($line);
                }
            }
            
            // Exibe o conteúdo formatado
            echo implode("\n", $logContent);
        } else {
            echo "Arquivo de log não encontrado.";
        }
    ?></pre>
</div>

<!-- Modal de Confirmação -->
<div id="modalConfirmacao">
    <div class="modal-content">
        <p>Recibo enviado com sucesso!</p>
        <button onclick="fecharModal()">OK</button>
    </div>
</div>

<?php include('../../baixo.php'); ?>

<script src="../../menu.js.hhvm"></script>
<script>
    // Alterna a visibilidade do formulário de configurações
    function enviarRecibo() {
        $.post("enviozap.php", $('#envioForm').serialize(), function(response) {
            $('#modalConfirmacao').fadeIn();
        });
    }

    function fecharModal() {
        $('#modalConfirmacao').fadeOut(function() {
            location.reload();
        });
    }
</script>


</body>
</html>
