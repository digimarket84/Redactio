/*!
 * Rédactio — admin.js
 * @copyright 2026 Guillaume JEUDY
 * @license   GNU GPL v3
 */
/* global redactioData, jQuery */
(function ($) {
    'use strict';

    var progressInterval = null;

    // ── Barre de progression (inline dans le tableau de bord) ─────────────────

    function startProgressPolling() {
        var $wrap  = $('#redactio-progress-notice');
        var $fill  = $('#redactio-bar-fill');
        var $label = $('#redactio-bar-label');

        $fill.css({ width: '0%', background: '' });
        $wrap.show();
        stopProgressPolling();

        progressInterval = setInterval(function () {
            $.ajax({
                url:    redactioData.ajaxurl,
                method: 'POST',
                data:   { action: 'redactio_get_progress', security: redactioData.nonce },
                success: function (response) {
                    if (!response.success || !response.data) return;
                    var data    = response.data;
                    var status  = data.status || 'idle';
                    var percent = data.percent || 0;

                    if ('running' === status) {
                        $fill.css('width', percent + '%');
                        $label.text(
                            '✨ Amélioration chunk ' + data.current + '/' + data.total +
                            ' (' + percent + '%)' +
                            (data.article ? ' — ' + data.article : '')
                        );
                        // Détection gel > 5 min.
                        var stale = Math.floor(Date.now() / 1000) - (data.last_update || 0);
                        if (data.last_update && stale > 300) {
                            $fill.css('background', '#f0a500');
                            $label.text('⚠️ Aucune progression depuis ' + Math.floor(stale / 60) + ' min.');
                        }

                    } else if ('done' === status) {
                        $fill.css({ width: '100%', background: '#00a32a' });
                        $label.text('✅ Terminé.');
                        stopProgressPolling();
                        setTimeout(function () { $wrap.fadeOut(); }, 3000);

                    } else if ('error' === status) {
                        $fill.css({ width: '100%', background: '#d63638' });
                        $label.text('❌ ' + (data.message || 'Erreur.'));
                        stopProgressPolling();
                    }
                },
            });
        }, 2000);
    }

    function stopProgressPolling() {
        if (progressInterval) {
            clearInterval(progressInterval);
            progressInterval = null;
        }
    }

    // ── Notice flottante (listes WP) ──────────────────────────────────────────

    function createFloatingNotice() {
        if ($('#redactio-floating-notice').length) return;
        $('body').append(
            '<div id="redactio-floating-notice">' +
            '<strong>Rédactio</strong> <span id="redactio-float-text"></span>' +
            '<div class="redactio-bar-track" style="margin-top:8px;">' +
            '<div class="redactio-bar-fill" id="redactio-float-fill"></div>' +
            '</div>' +
            '<p id="redactio-float-label" style="margin:4px 0 0;font-size:11px;color:#555;"></p>' +
            '</div>'
        );
    }

    function startFloatingPolling( successCallback ) {
        createFloatingNotice();
        var $notice = $('#redactio-floating-notice');
        var $fill   = $('#redactio-float-fill');
        var $label  = $('#redactio-float-label');

        $fill.css({ width: '0%', background: '#00a32a' });
        $notice.show();
        stopProgressPolling();

        progressInterval = setInterval(function () {
            $.ajax({
                url:    redactioData.ajaxurl,
                method: 'POST',
                data:   { action: 'redactio_get_progress', security: redactioData.nonce },
                success: function (response) {
                    if (!response.success || !response.data) return;
                    var data    = response.data;
                    var status  = data.status || 'idle';
                    var percent = data.percent || 0;

                    if ('running' === status) {
                        $fill.css('width', percent + '%');
                        $label.text(
                            'Chunk ' + data.current + '/' + data.total +
                            ' (' + percent + '%)' +
                            (data.article ? ' — ' + data.article : '')
                        );

                    } else if ('done' === status) {
                        $fill.css({ width: '100%', background: '#00a32a' });
                        $label.text('✅ Terminé.');
                        stopProgressPolling();
                        if (typeof successCallback === 'function') successCallback();
                        setTimeout(function () { $notice.fadeOut(); }, 3000);

                    } else if ('error' === status) {
                        $fill.css({ width: '100%', background: '#d63638' });
                        $label.text('❌ ' + (data.message || 'Erreur.'));
                        stopProgressPolling();
                    }
                },
            });
        }, 2000);
    }

    // ── Helper : afficher le résultat dans la ligne du tableau ───────────────

    function showResult($el, success, msg) {
        $el.css('color', success ? '#00a32a' : '#d63638').html(msg).show();
    }

    // ── Document ready ────────────────────────────────────────────────────────

    $(document).ready(function () {

        // ── Bouton "✨ Améliorer" (tableau de bord settings-page) ─────────────
        $(document).on('click', '.redactio-improve-btn', function () {
            var $btn    = $(this);
            var postId  = $btn.data('post-id');
            var title   = $btn.data('title');
            var $result = $('#result-' + postId);

            if (!window.confirm('Améliorer la lisibilité de "' + title + '" ?\n\nLe contenu de l\'article sera réécrit pour plus de clarté.')) {
                return;
            }

            $btn.prop('disabled', true).text('⏳ En cours…');
            $result.css('color', '#666').text('Amélioration en cours…').show();
            startProgressPolling();

            $.ajax({
                url:     redactioData.ajaxurl,
                method:  'POST',
                timeout: 320000,
                data: {
                    action:   'redactio_improve',
                    security: redactioData.nonce,
                    post_id:  postId,
                },
                success: function (response) {
                    stopProgressPolling();
                    $('#redactio-progress-notice').hide();
                    if (response.success) {
                        showResult($result, true, response.data.message);
                        $btn.prop('disabled', false).text('✨ Améliorer');
                    } else {
                        showResult($result, false, '❌ ' + (response.data || redactioData.i18n.error));
                        $btn.prop('disabled', false).text('✨ Améliorer');
                    }
                },
                error: function () {
                    stopProgressPolling();
                    showResult($result, false, '❌ Timeout ou erreur réseau.');
                    $btn.prop('disabled', false).text('✨ Améliorer');
                },
            });
        });

        // ── Bouton "🔍 Régénérer SEO" (tableau de bord settings-page) ─────────
        $(document).on('click', '.redactio-seo-btn', function () {
            var $btn    = $(this);
            var postId  = $btn.data('post-id');
            var title   = $btn.data('title');
            var $result = $('#result-' + postId);

            if (!window.confirm('Régénérer le SEO de "' + title + '" ?\n\nLes meta Yoast et les tags seront mis à jour.')) {
                return;
            }

            $btn.prop('disabled', true).text('⏳ Génération…');
            $result.css('color', '#666').text('Appel API en cours…').show();

            $.ajax({
                url:     redactioData.ajaxurl,
                method:  'POST',
                timeout: 60000,
                data: {
                    action:   'redactio_regenerate_seo',
                    security: redactioData.nonce,
                    post_id:  postId,
                },
                success: function (response) {
                    if (response.success) {
                        showResult($result, true,
                            response.data.message + '<br><em>Keyword : ' +
                            response.data.focus_keyword + ' | Tags : ' +
                            response.data.tags.join(', ') + '</em>'
                        );
                    } else {
                        showResult($result, false, '❌ ' + (response.data || redactioData.i18n.error));
                    }
                    $btn.prop('disabled', false).text('🔍 Régénérer SEO');
                },
                error: function () {
                    showResult($result, false, '❌ Timeout ou erreur réseau.');
                    $btn.prop('disabled', false).text('🔍 Régénérer SEO');
                },
            });
        });

        // ── Row actions (listes WP Articles / Pages) ──────────────────────────
        $(document).on('click', 'a.redactio-improve-btn', function (e) {
            e.preventDefault();
            var $link  = $(this);
            var postId = $link.data('post-id');
            var title  = $link.data('title');

            if (!window.confirm('Améliorer la lisibilité de "' + title + '" ?\n\nLe contenu sera réécrit.')) {
                return;
            }

            $link.text('⏳ En cours…');
            startFloatingPolling();

            $.ajax({
                url:     redactioData.ajaxurl,
                method:  'POST',
                timeout: 320000,
                data: {
                    action:   'redactio_improve',
                    security: redactioData.nonce,
                    post_id:  postId,
                },
                success: function (response) {
                    stopProgressPolling();
                    var $notice = $('#redactio-floating-notice');
                    if (response.success) {
                        $notice.css('border-left-color', '#00a32a');
                        $('#redactio-float-text').text(response.data.message);
                        $link.text('✅ Amélioré');
                    } else {
                        $notice.css('border-left-color', '#d63638');
                        $('#redactio-float-text').text('❌ ' + (response.data || redactioData.i18n.error));
                        $link.text('✨ Améliorer');
                    }
                    setTimeout(function () { $notice.fadeOut(); }, 5000);
                },
                error: function () {
                    stopProgressPolling();
                    $('#redactio-float-text').text('❌ Timeout ou erreur réseau.');
                    $link.text('✨ Améliorer');
                },
            });
        });

        $(document).on('click', 'a.redactio-seo-btn', function (e) {
            e.preventDefault();
            var $link  = $(this);
            var postId = $link.data('post-id');
            var title  = $link.data('title');

            if (!window.confirm('Régénérer le SEO de "' + title + '" ?')) return;

            $link.text('⏳ SEO…');
            createFloatingNotice();
            $('#redactio-floating-notice').show();
            $('#redactio-float-text').text('Appel API en cours…');

            $.ajax({
                url:     redactioData.ajaxurl,
                method:  'POST',
                timeout: 60000,
                data: {
                    action:   'redactio_regenerate_seo',
                    security: redactioData.nonce,
                    post_id:  postId,
                },
                success: function (response) {
                    if (response.success) {
                        $('#redactio-float-text').text(response.data.message);
                        $link.text('✅ SEO');
                    } else {
                        $('#redactio-float-text').text('❌ ' + (response.data || redactioData.i18n.error));
                        $link.text('🔍 SEO');
                    }
                    setTimeout(function () { $('#redactio-floating-notice').fadeOut(); }, 5000);
                },
                error: function () {
                    $('#redactio-float-text').text('❌ Timeout.');
                    $link.text('🔍 SEO');
                },
            });
        });

        // ── Forcer la mise à jour (onglet Avancé) ─────────────────────────────
        $('#redactio-force-install').on('click', function () {
            var $btn    = $(this);
            var $result = $('#redactio-force-install-result');

            if (!window.confirm('Télécharger et installer la dernière version de Rédactio depuis GitHub ?')) return;

            $btn.prop('disabled', true).text('⏳ Installation…');
            $result.css('color', '#666').text('Téléchargement en cours…');

            $.ajax({
                url:     redactioData.ajaxurl,
                method:  'POST',
                timeout: 120000,
                data: {
                    action:   'redactio_force_install',
                    security: redactioData.nonce,
                },
                success: function (response) {
                    if (response.success) {
                        $result.css('color', '#00a32a').text(response.data.message);
                    } else {
                        $result.css('color', '#d63638').text('❌ ' + (response.data || redactioData.i18n.error));
                    }
                    $btn.prop('disabled', false).text('⬇️ Installer la dernière version');
                },
                error: function () {
                    $result.css('color', '#d63638').text('❌ Timeout ou erreur réseau.');
                    $btn.prop('disabled', false).text('⬇️ Installer la dernière version');
                },
            });
        });

        // ── Vider les logs ────────────────────────────────────────────────────
        $('#redactio-clear-logs').on('click', function () {
            if (!window.confirm('Vider tous les logs Rédactio ?')) return;
            var $btn    = $(this);
            var $result = $('#redactio-clear-logs-result');
            $btn.prop('disabled', true);
            $.ajax({
                url:    redactioData.ajaxurl,
                method: 'POST',
                data:   { action: 'redactio_clear_logs', security: redactioData.nonce },
                success: function (response) {
                    $result.css('color', response.success ? '#00a32a' : '#d63638')
                           .text(response.success ? '✅ Logs vidés.' : '❌ Erreur.');
                    $btn.prop('disabled', false);
                },
            });
        });

    });

}(jQuery));
