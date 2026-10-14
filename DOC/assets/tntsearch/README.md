# TNTSearch

## Kurzbeschreibung

`TNTSearch` stellt die Volltextsuche von 365CMS bereit.

## Quellordner

- `CMS/assets/tntsearchhelper/`
- `CMS/assets/tntsearchsrc/`

## Verwendung in 365CMS

- direkte Nutzung in `CMS/core/Services/SearchService.php` (`search()`, `searchAll()`, Plugin-Indizes über `search_register_indices`)
- Die Seitensuche `/search` nutzt seit 3.4.06 `CMS/core/Services/SiteSearchService.php` (alle Suchbegriffe müssen im sichtbaren Text vorkommen, Sortierung nach Relevanz oder Datum) und nicht mehr den TNTSearch-Index: Dieser enthält das Editor.js-JSON und verknüpft Suchbegriffe mit ODER.

## Website / GitHub

- Website: https://packagist.org/packages/teamtnt/tntsearch
- GitHub: https://github.com/teamtnt/tntsearch