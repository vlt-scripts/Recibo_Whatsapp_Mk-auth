# Recibo_Whatsapp_Mk-auth
Esse Addon tem como Objetivo enviar Comprovante de Pagamento, pelo whatsapp, 

# Resumo

TRIGGER insere dados na tabela brl_pago após a inserção de um novo registro, mas só se:

1. O status do novo registro for 'pago'.
2. O campo zap em sis_cliente não for 'não' (cliente autorizado). " Recebe WhatsAPP: Sim "
3. campo cli_ativado em sis_cliente não for 'n' (cliente ativo).
Se essas condições forem atendidas, os dados são inseridos com a data e hora atuais.

----------------------------------------------------------------------------------------------

1. Para colocar seu Token e IP ou url da API é somente ir na engrenagem no canto direito do addon que vai ter os campos designados para isso.

2. Para agendar é só clicar no ícone de calendário, que lá vai ter opção de agendar o Intervalo (min):.

3. Para funcionar na TUX 4.19 precisa adicionar permissões do " apparmor "

4. Vá para o diretório /etc/apparmor.d e abra o arquivo usr.sbin.php-fpm7.3.

5. Adicione estas linha no arquivo:

        #Addon Recibo Whatsapp
        /opt/mk-auth/dados/Recibo_Whatsapp/ rw,
        /opt/mk-auth/dados/Recibo_Whatsapp/** rw,



   


 Caso não queira reiniciar o MK-auth só dar esses dois comando abaixo.

```
sudo apparmor_parser -r /etc/apparmor.d/usr.sbin.php-fpm7.3
```
```
sudo service php7.3-fpm restart
```

eu usei como base para esse addon o codigo Membro do Forum, 

https://mk-auth.com.br/forum/topics/rebido-de-pagamento-automatico-pelo-whatsapp

e modifiquei para usar com a api 

https://mk-auth.com.br/forum/topics/envio-de-mensagem-via-whatsapp-100-gratuito


