# Viagens Field para GLPI

> Autor: **GLPI Salvador** · Licença: **GPLv3+** · Compatível com GLPI **11.0.0 a 12.x**

Controle das **viagens de campo** dos técnicos e do **dinheiro** que elas consomem. Cada viagem fica ligada a um chamado, mudança, problema ou projeto, e o custo dela entra num **livro-caixa** global.

## O que o plugin faz

### Viagens
- Ligadas a **chamados, mudanças, problemas ou projetos**, com uma aba **Viagens** em cada um.
- **Formulário nativo** do GLPI: data e hora, técnico, requerente, entidade de origem e de destino, meio de transporte, quilômetros, custo, situação (pendente, efetuada, cancelada) e observações, com máscaras para valor (1.234,56) e km (12,5).
- **Comprovantes:**
  - fotos ou documentos ligados à viagem como documentos nativos do GLPI;
  - o navegador reduz fotos grandes e envia em partes, o que funciona mesmo com limite baixo de upload no PHP;
  - miniaturas no formulário, na aba e no painel.
- **Histórico** de alterações.
- Colunas **"Custo de viagens"** e **"Viagens"** na busca dos itens.

### Livro-caixa global
- **Entradas** e **estornos** somam; **retiradas** e **despesas de viagem** subtraem.
- Criar uma viagem **debita** o caixa:
  - alterar o custo ou o status lança o **ajuste**;
  - **cancelar ou excluir estorna**;
  - reativar debita de novo.
- Viagens canceladas nunca custam nada.
- O saldo é sempre recalculado pela soma dos lançamentos, e cada lançamento guarda o saldo antes e depois.

### Painel
O painel tem três abas: **Viagens**, **Relatórios** (gráficos com o ECharts do GLPI) e **Caixa**. Tudo é restrito às entidades ativas do usuário.

## Configuração

- **Permissões** em três áreas, cada uma com perfis e usuários: **registrar** viagens, ver **relatórios** e movimentar o **caixa**. Administradores sempre têm acesso.
- **Caixa e opções das viagens.**

O menu fica em **Ferramentas → Viagens**.

---

## Download e instalação

1. Baixe o arquivo `viagensfield-X.Y.Z.zip` da **[última versão](../../releases/latest)**. Use o arquivo anexado à release, não o "Source code".
2. Descompacte dentro da pasta `plugins/` do GLPI. O resultado deve ser `plugins/viagensfield/setup.php`.
3. Ajuste o dono dos arquivos para o usuário do servidor web, por exemplo:
   ```bash
   chown -R www-data:www-data /var/www/glpi/plugins/viagensfield
   ```
4. No GLPI, vá em **Configurar → Plugins** e clique em **Instalar** e depois em **Ativar**. Pela linha de comando:
   ```bash
   php bin/console plugin:install viagensfield -u <usuário administrador>
   php bin/console plugin:activate viagensfield
   ```

A instalação cria as tabelas, as configurações padrão e as ações automáticas do plugin, e funciona num GLPI sem nada configurado antes.

### Atualização

Substitua a pasta `plugins/viagensfield` pela versão nova e rode **Instalar** de novo, ou `php bin/console plugin:install viagensfield -f`. Depois, ative o plugin. As tabelas e colunas novas são criadas sem perder os dados.

### Desinstalação

A desinstalação **não apaga as tabelas do plugin**: reinstalar recupera os dados.

## Versões

O histórico, com o que mudou em cada versão e o arquivo para download, está em **[Releases](../../releases)**. Cada versão entrou por um **[pull request](../../pulls?q=is%3Apr)**.

## Licença

Distribuído sob a **GNU General Public License v3.0 ou posterior**. Veja o arquivo [LICENSE](LICENSE).