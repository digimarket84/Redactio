# ✍️ Rédactio

**Plugin WordPress d'amélioration de la lisibilité et du SEO via l'API Claude (Anthropic).**

Rédactio analyse vos articles et pages WordPress, améliore automatiquement la clarté du texte et optimise vos données SEO Yoast — en un clic, sans retraduire.

---

## ✨ Fonctionnalités

- **Tableau de bord** — Liste tous vos articles et pages publiés avec leurs scores SEO et lisibilité Yoast (●vert / ●orange / ●rouge)
- **Améliorer la lisibilité** — Soumet le contenu de l'article à Claude pour raccourcir les phrases, ajouter des mots de liaison et rendre le texte plus fluide. Le HTML est préservé intégralement.
- **Régénérer le SEO** — Génère automatiquement `meta_title`, `meta_description`, `focus_keyword` et les tags WordPress via Claude, à partir du contenu complet de l'article.
- **Chunking automatique** — Les longs articles (>25 000 caractères) sont découpés en sections et traités en plusieurs appels API avec cache de reprise après interruption.
- **Barre de progression temps réel** — Polling toutes les 2 secondes, notice flottante sur les listes WP.
- **Row actions** — Boutons "✨ Améliorer" et "🔍 SEO" directement dans les listes WP > Articles / Pages.
- **Mises à jour automatiques** — Vérification du build GitHub, bouton "Forcer la mise à jour" intégré.

---

## 🖥️ Interface

### Tableau de bord

| Type | Article | SEO | Lisibilité | Actions |
|---|---|---|---|---|
| Article | Mon article | ● 78 | ● 32 | ✨ Améliorer · 🔍 SEO |
| Page | Accueil | ● 55 | ● 85 | ✨ Améliorer · 🔍 SEO |

Les scores sont récupérés depuis les meta Yoast (`_yoast_wpseo_linkdex`, `_yoast_wpseo_content_score`).

### Onglets

| Onglet | Contenu |
|---|---|
| 📋 Tableau de bord | Listing paginé avec scores, tri et filtres |
| 📄 Logs | Dernières 150 lignes de log avec coloration syntaxique |
| ⚙️ Réglages | Clé API, modèle Claude, types de contenu, débogage |
| 🔄 Avancé | Version installée, build GitHub, Force Install |

---

## 🚀 Installation

1. Télécharger le ZIP depuis la [dernière release GitHub](https://github.com/digimarket84/Redactio/releases/latest)
2. **WordPress Admin → Extensions → Ajouter → Téléverser le fichier ZIP**
3. Activer le plugin
4. Aller dans **Réglages → Rédactio**
5. Saisir votre clé API Claude (obtenue sur [console.anthropic.com](https://console.anthropic.com))

### Recommandé : clé API dans wp-config.php

```php
define('REDACTIO_CLAUDE_API_KEY', 'sk-ant-...');
```

---

## ⚙️ Configuration

| Option | Description | Défaut |
|---|---|---|
| Clé API Claude | Clé `sk-ant-...` (chiffrée en BDD) | — |
| Modèle Claude | `claude-opus-4-5`, `claude-sonnet-4-5`, `claude-haiku-3-5` | `claude-opus-4-5` |
| Types de contenu | Articles, Pages, types personnalisés | `post`, `page` |
| Débogage | Logs détaillés dans `wp-content/redactio-debug.log` | désactivé |

---

## 🔧 Compatibilité

- **WordPress** : 5.6+
- **PHP** : 7.4+
- **Yoast SEO** : compatible (scores et meta optionnels — le plugin fonctionne sans Yoast)

---

## 📐 Architecture

```
redactio/
├── redactio.php                        ← Fichier principal, hooks, autoload
├── version.json                        ← {"version":"1.0.0","build":1}
├── includes/
│   ├── class-redactio-improver.php     ← API Claude, chunking, SEO
│   ├── class-redactio-updater.php      ← GitHub Releases
│   └── class-redactio-logger.php       ← Logs avec rotation 5 Mo
├── admin/
│   ├── class-redactio-admin.php        ← Menu, row actions, AJAX
│   ├── settings-page.php               ← Templates (4 onglets)
│   └── assets/
│       ├── admin.css
│       └── admin.js
└── .github/workflows/release.yml       ← ZIP auto sur tag v*.*.*
```

---

## 🔒 Sécurité

- Clé API chiffrée via **libsodium** (PHP 7.2+) avant stockage en base de données
- Vérification **nonce WordPress** sur tous les appels AJAX
- Contrôle des **capacités utilisateur** (`edit_posts`, `manage_options`)
- HTML des articles préservé via `wp_kses_post()` après amélioration

---

## 📄 Licence

GNU General Public License v3.0 — © 2026 Guillaume JEUDY / [GeekLabo.fr](https://geeklabo.fr)
