=== CoderEmbassy AWAC — Accessibility for WooCommerce ===
Contributors: coderembassy
Tags: woocommerce, accessibility, wcag, audit, a11y
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.3.6
Requires Plugins: woocommerce
WC requires at least: 8.3
WC tested up to: 10.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Local, evidence-first accessibility auditing for WooCommerce shopping journeys.

== Description ==

CoderEmbassy AWAC helps store owners inspect WooCommerce pages and flows with
local automated checks, WooCommerce-aware rules, human review checklists, and
redacted evidence history.

AWAC reports observed evidence and limitations. It does not claim that a site
is legally compliant, fully conformant, or covered by a compliance score. A
knowledgeable human accessibility review is required.

The plugin does not inject an accessibility overlay or widget. Scan sessions
use same-origin, short-lived runner links and isolate supported WooCommerce
session and stock behavior. Scan excerpts are redacted before local storage.

== Features ==

* Same-origin initial page-set auditing for WooCommerce journeys.
* Local axe-core checks plus WooCommerce-aware static rules.
* Evidence-first Issues, Reports, and human Manual Checks workspaces.
* Redacted excerpts, lifecycle history, dismissal notes, and retention controls.
* Accessible admin shell with keyboard navigation, focus management, dark mode,
  and printable semantic reports.
* Safe-mode boundary for remediation features and fail-closed runner channels.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the plugin to `/wp-content/plugins/` or install it from the Plugins
   screen.
3. Activate CoderEmbassy AWAC.
4. Open **WooCommerce → AWAC**, review Settings, and begin in Audit.

== Privacy ==

AWAC does not send scanned page content or accessibility findings to
CoderEmbassy or any scanning service. The Free plugin contains no licensing
or premium interface.

Scan evidence is retained locally according to the configured retention period.
Uninstall retains evidence unless the site owner explicitly enables permanent
removal in `wp-config.php`.

== Frequently Asked Questions ==

= Does AWAC make my store compliant? =

No. AWAC helps identify and track accessibility barriers. Automated checks cover
only part of WCAG, and human evaluation is required.

= Does AWAC use an overlay? =

No. AWAC does not inject an accessibility overlay or widget. Any remediation is
targeted, reversible, and subject to the plugin's safety controls.

= Does scanning submit orders or contact payment gateways? =

No. The runner uses isolated session handling and does not submit a real order
or capture payment-iframe content.

== Changelog ==

= 0.3.6 =
* Kept the Audit page URL and viewport controls inside their responsive card
  columns at narrow WordPress workspace widths.

= 0.3.5 =
* Fixed the Issues filter card so its controls reflow from four columns to two
  and then one without escaping the card or forcing horizontal overflow.

= 0.3.4 =
* Extended the neutral dashboard extension contract so separately packaged
  add-ons can provide an icon, with the generic add-on icon as the fallback.

= 0.3.3 =
* Added a neutral active-extension badge slot to the shared dashboard hero.
  Separately packaged add-ons supply their own status and version.

= 0.3.2 =
* Added a neutral add-on extension contract for the shared AWAC dashboard.
  The Free package still contains no Pro workflow, licensing, or upgrade UI.

= 0.3.1 =
* Removed Pro licensing, Pro workflow placeholders, and Pro backend classes
  from the Free package. Pro functionality now lives only in the separately
  packaged CoderEmbassy AWAC Pro add-on.

= 0.3.0 =
* Evidence-first Audit, Issues, Manual Checks, Statement, Reports, and Help
  workspaces.
* Same-origin runner links, redaction, scan hygiene, safe mode, and retention
  controls.
