<?php

/**
 * Plugin Viagens Field - comprovantes das viagens.
 * São documentos nativos do GLPI (glpi_documents) ligados à viagem por Document_Item.
 * O envio é feito em partes pelo front/ajax.php (o PHP do servidor aceita só 2 MB por upload):
 * o navegador reduz fotos grandes, manda em pedaços de 1 MB e, ao salvar a viagem,
 * os arquivos montados viram documentos.
 */
class PluginViagensfieldComprovante extends CommonGLPI
{
    public const TAMANHO_MAXIMO = 20971520; // 20 MB
    public const TAMANHO_PARTE = 1048576;   // 1 MB
    public const EXTENSOES = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'bmp',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'txt', 'csv', 'zip', 'rar',
    ];

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Comprovantes' : 'Comprovante';
    }

    public static function canView(): bool
    {
        return PluginViagensfieldViagem::canView();
    }

    // =====================================================================
    // Consulta
    // =====================================================================

    /** [viagens_id => quantidade] */
    public static function contarPorViagem(array $ids): array
    {
        $contagem = [];
        foreach (self::documentosPorViagem($ids) as $viagem => $docs) {
            $contagem[$viagem] = count($docs);
        }
        return $contagem;
    }

    /** Documentos de várias viagens: [viagens_id => [[id, nome, url, imagem], ...]] */
    public static function documentosPorViagem(array $ids): array
    {
        global $DB, $CFG_GLPI;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $lista = [];
        foreach ($DB->request([
            'SELECT'     => ['di.items_id', 'd.id', 'd.name', 'd.filename', 'd.mime'],
            'FROM'       => 'glpi_documents_items AS di',
            'INNER JOIN' => ['glpi_documents AS d' => ['ON' => ['d' => 'id', 'di' => 'documents_id']]],
            'WHERE'      => ['di.itemtype' => PluginViagensfieldViagem::class, 'di.items_id' => $ids, 'd.is_deleted' => 0],
            'ORDER'      => 'd.id ASC',
        ]) as $r) {
            $viagem = (int) $r['items_id'];
            $lista[$viagem][] = [
                'id'     => (int) $r['id'],
                'nome'   => (string) ($r['filename'] ?: $r['name']),
                'imagem' => str_starts_with((string) $r['mime'], 'image/'),
                // O download nativo confere a permissão pelo item vinculado
                'url'    => $CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . (int) $r['id']
                    . '&itemtype=' . rawurlencode(PluginViagensfieldViagem::class) . '&items_id=' . $viagem,
            ];
        }
        return $lista;
    }

    /** Ícone Tabler conforme o tipo do arquivo */
    public static function icone(string $nome, bool $imagem = false): string
    {
        if ($imagem) {
            return 'ti ti-photo';
        }
        return match (strtolower(pathinfo($nome, PATHINFO_EXTENSION))) {
            'pdf'                => 'ti ti-file-type-pdf',
            'doc', 'docx', 'odt' => 'ti ti-file-type-doc',
            'xls', 'xlsx', 'ods', 'csv' => 'ti ti-file-spreadsheet',
            'zip', 'rar'         => 'ti ti-file-zip',
            default              => 'ti ti-file',
        };
    }

    /** Links compactos (ícone por arquivo) para listas e tabelas */
    public static function linksHtml(array $docs): string
    {
        if (!$docs) {
            return '<span class="text-muted">—</span>';
        }
        $e = [PluginViagensfieldConfig::class, 'e'];
        $h = '<span class="viagensfield-docs">';
        foreach ($docs as $d) {
            $h .= '<a href="' . $e($d['url']) . '" target="_blank" rel="noopener" title="' . $e($d['nome']) . '">'
                . '<i class="' . self::icone($d['nome'], $d['imagem']) . '"></i></a>';
        }
        return $h . '</span>';
    }

    /** Miniaturas (fotos) e cartões (documentos) para o formulário da viagem */
    public static function galeriaHtml(array $docs): string
    {
        $e = [PluginViagensfieldConfig::class, 'e'];
        $h = '';
        foreach ($docs as $d) {
            $h .= '<a class="viagensfield-comprovante" href="' . $e($d['url']) . '" target="_blank" rel="noopener" title="' . $e($d['nome']) . '">';
            if ($d['imagem']) {
                $h .= '<img src="' . $e($d['url']) . '" alt="' . $e($d['nome']) . '" loading="lazy">';
            } else {
                $h .= '<i class="' . self::icone($d['nome']) . '"></i>';
            }
            $h .= '<span>' . $e($d['nome']) . '</span></a>';
        }
        return $h;
    }

    // =====================================================================
    // Envio em partes
    // =====================================================================

    public static function pastaEnvio(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/viagensfield/envio';
    }

    private static function pastaDoEnvio(string $id): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            return null;
        }
        $pasta = self::pastaEnvio() . '/' . $id;
        $meta = self::lerMeta($pasta);
        if ($meta === null || (int) $meta['users_id'] !== (int) Session::getLoginUserID()) {
            return null;
        }
        return $pasta;
    }

    private static function lerMeta(string $pasta): ?array
    {
        $arquivo = $pasta . '/meta.json';
        if (!is_file($arquivo)) {
            return null;
        }
        $meta = json_decode((string) file_get_contents($arquivo), true);
        return is_array($meta) ? $meta : null;
    }

    private static function removerPasta(string $pasta): void
    {
        if (!is_dir($pasta)) {
            return;
        }
        foreach (glob($pasta . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($pasta);
    }

    /** Remove envios abandonados (mais de 1 dia) */
    public static function limparAntigos(): void
    {
        foreach (glob(self::pastaEnvio() . '/*', GLOB_ONLYDIR) ?: [] as $pasta) {
            if (filemtime($pasta) < time() - 86400) {
                self::removerPasta($pasta);
            }
        }
    }

    /** Nome seguro, mantendo acentos e a extensão */
    public static function nomeSeguro(string $nome): string
    {
        $nome = basename(str_replace('\\', '/', $nome));
        $nome = preg_replace('/[^\p{L}\p{N}._ ()-]+/u', '_', $nome) ?? 'arquivo';
        $nome = trim($nome, ' ._');
        return mb_substr($nome !== '' ? $nome : 'arquivo', -120);
    }

    /** @return array{0: ?string, 1: string} [id, mensagem de erro] */
    public static function iniciar(string $nome, int $tamanho): array
    {
        $nome = self::nomeSeguro($nome);
        $extensao = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        if (!in_array($extensao, self::EXTENSOES, true)) {
            return [null, 'Tipo de arquivo não permitido: ' . $nome . '. Use fotos, PDF, documentos do Office, TXT, CSV ou ZIP.'];
        }
        if ($tamanho <= 0 || $tamanho > self::TAMANHO_MAXIMO) {
            return [null, 'O arquivo ' . $nome . ' passa de 20 MB.'];
        }
        self::limparAntigos();
        $id = bin2hex(random_bytes(16));
        $pasta = self::pastaEnvio() . '/' . $id;
        if (!is_dir($pasta) && !@mkdir($pasta, 0755, true)) {
            return [null, 'Não foi possível preparar o envio no servidor.'];
        }
        file_put_contents($pasta . '/meta.json', json_encode([
            'users_id' => (int) Session::getLoginUserID(),
            'nome'     => $nome,
            'tamanho'  => $tamanho,
            'partes'   => (int) ceil($tamanho / self::TAMANHO_PARTE),
        ], JSON_UNESCAPED_UNICODE));
        return [$id, ''];
    }

    public static function gravarParte(string $id, int $indice, array $arquivo): string
    {
        $pasta = self::pastaDoEnvio($id);
        if ($pasta === null) {
            return 'Envio não encontrado.';
        }
        $meta = self::lerMeta($pasta);
        if ($indice < 0 || $indice >= (int) $meta['partes']) {
            return 'Parte inválida.';
        }
        if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($arquivo['tmp_name'] ?? ''))) {
            return 'Falha ao receber a parte ' . ($indice + 1) . '.';
        }
        if ((int) $arquivo['size'] > self::TAMANHO_PARTE) {
            return 'Parte maior que o permitido.';
        }
        if (!move_uploaded_file($arquivo['tmp_name'], $pasta . '/parte_' . $indice)) {
            return 'Não foi possível gravar a parte ' . ($indice + 1) . '.';
        }
        return '';
    }

    /** Junta as partes; devolve [dados do arquivo, erro] */
    public static function concluir(string $id): array
    {
        $pasta = self::pastaDoEnvio($id);
        if ($pasta === null) {
            return [null, 'Envio não encontrado.'];
        }
        $meta = self::lerMeta($pasta);
        $destino = $pasta . '/arquivo';
        $saida = fopen($destino, 'wb');
        for ($i = 0; $i < (int) $meta['partes']; $i++) {
            $parte = $pasta . '/parte_' . $i;
            if (!is_file($parte)) {
                fclose($saida);
                return [null, 'O envio ficou incompleto. Tente de novo.'];
            }
            $entrada = fopen($parte, 'rb');
            stream_copy_to_stream($entrada, $saida);
            fclose($entrada);
        }
        fclose($saida);
        for ($i = 0; $i < (int) $meta['partes']; $i++) {
            @unlink($pasta . '/parte_' . $i);
        }
        if (filesize($destino) !== (int) $meta['tamanho']) {
            self::removerPasta($pasta);
            return [null, 'O arquivo chegou com tamanho diferente do esperado. Tente de novo.'];
        }
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($destino);
        return [[
            'id'      => $id,
            'nome'    => $meta['nome'],
            'tamanho' => Toolbox::getSize((int) $meta['tamanho']),
            'imagem'  => str_starts_with($mime, 'image/'),
        ], ''];
    }

    public static function cancelar(string $id): void
    {
        $pasta = self::pastaDoEnvio($id);
        if ($pasta !== null) {
            self::removerPasta($pasta);
        }
    }

    /**
     * Transforma os envios concluídos em documentos da viagem.
     * Os envios são consumidos (a pasta é apagada), então chamar duas vezes não duplica.
     */
    public static function anexar(PluginViagensfieldViagem $viagem, array $ids): int
    {
        $arquivos = [];
        $prefixos = [];
        $pastas = [];
        foreach (array_unique(array_map('strval', $ids)) as $id) {
            $pasta = self::pastaDoEnvio($id);
            if ($pasta === null || !is_file($pasta . '/arquivo')) {
                continue;
            }
            $meta = self::lerMeta($pasta);
            $prefixo = uniqid('', true);
            $nomeTmp = $prefixo . $meta['nome'];
            if (!@rename($pasta . '/arquivo', GLPI_TMP_DIR . '/' . $nomeTmp)) {
                continue;
            }
            $arquivos[] = $nomeTmp;
            $prefixos[] = $prefixo;
            $pastas[] = $pasta;
        }
        foreach ($pastas as $pasta) {
            self::removerPasta($pasta);
        }
        if (!$arquivos) {
            return 0;
        }

        global $DB;
        $antes = count($DB->request(['FROM' => 'glpi_documents_items', 'WHERE' => ['itemtype' => PluginViagensfieldViagem::class, 'items_id' => $viagem->getID()]]));
        // addFiles lê o prefixo de $this->input: preenche só durante a chamada
        $inputOriginal = $viagem->input;
        $viagem->input = ['_prefix_filename' => $prefixos] + (is_array($inputOriginal) ? $inputOriginal : []);
        $viagem->addFiles(['_filename' => $arquivos, '_prefix_filename' => $prefixos], ['name' => 'filename', 'content_field' => 'comment']);
        $viagem->input = $inputOriginal;
        foreach ($arquivos as $nomeTmp) {
            if (is_file(GLPI_TMP_DIR . '/' . $nomeTmp)) {
                @unlink(GLPI_TMP_DIR . '/' . $nomeTmp);
            }
        }
        $depois = count($DB->request(['FROM' => 'glpi_documents_items', 'WHERE' => ['itemtype' => PluginViagensfieldViagem::class, 'items_id' => $viagem->getID()]]));
        return max(0, $depois - $antes);
    }
}
