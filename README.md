# Magento 2 × Mollie — Auto Docs (Starter)

This repository builds a private MkDocs site automatically from the public Mollie Magento 2 plugin source.

- GitHub Actions clones https://github.com/mollie/magento2 into `vendor/mollie/module-payment`
- `generator/generate-docs.php` scans for API endpoints and writes Markdown into `docs/`
- MkDocs builds that Markdown into a static site and publishes it to the `gh-pages` branch

