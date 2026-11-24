# StaticSiteBundle

Generate a static site by prerendering your Symfony routes into plain HTML (and other) files,
ready to be served by any static host or CDN.

Read [Introducing StaticSiteBundle](https://baksla.sh/blog/introducing-static-site-bundle) for the motivation behind it.

## Installation

```bash
composer require bakslash/static-site-bundle
```

If you don't use Symfony Flex, register the bundle manually:

```php
// config/bundles.php
return [
    // ...
    Bakslash\StaticSiteBundle\BakslashStaticSiteBundle::class => ['all' => true],
];
```

## Usage

Mark the controllers you want to export with `#[Prerender]`:

```php
use Bakslash\StaticSiteBundle\Attribute\Prerender;

final class ViewHomepage
{
    #[Prerender]
    #[Route('/', name: 'homepage')]
    public function __invoke(): Response
    {
        // ...
    }
}
```

On a class, it marks every action it holds; a method-level attribute overrides it.

For a controller you don't own, such as `TemplateController`, mark the route where you declare
it instead:

```php
$routes->add('legal_notices', '/legal-notices')
    ->controller(TemplateController::class)
    ->defaults([
        'template' => 'pages/legal_notices.html.twig',
        Prerender::ROUTE_DEFAULTS_KEY => true,
    ]);
```

### Routes with parameters

Point `#[Prerender]` at a `ParamsProviderInterface` implementation that yields one parameter
set per page to generate:

```php
use Bakslash\StaticSiteBundle\ParamsProvider\ParamsProviderInterface;

final class ArticleParamsProvider implements ParamsProviderInterface
{
    public function __construct(private ArticleRepository $articles)
    {
    }

    public function provideParameterSets(): iterable
    {
        foreach ($this->articles->findAll() as $article) {
            yield ['slug' => $article->slug];
        }
    }
}
```

```php
#[Prerender(params: ArticleParamsProvider::class)]
#[Route('/articles/{slug}', name: 'article')]
public function __invoke(string $slug): Response
{
    // ...
}
```

Short lists can be inlined:
`#[Prerender(params: [['slug' => 'first'], ['slug' => 'second']])]`.

### Localized routes

Routes localized through a path per locale (`#[Route(['en' => '/articles/{slug}', 'fr' => '/fr/articles/{slug}'])]`)
are generated once per locale. When the translator is enabled (`symfony/translation` installed),
each locale variant runs its params provider inside that locale (`LocaleSwitcher::runWithLocale()`),
so the provider yields locale-dependent values, such as translated slugs, by reading the current
locale. Without the translator, nothing switches the locale: every variant's provider runs in
the current one, so a locale-dependent provider yields the same values for all of them. Any other
route with a `_locale` default is rendered in that locale, so its provider runs in it too. A route
with a `{_locale}` placeholder, in its path or host, serves every locale, so its provider runs as is.

`--route` matches both the name of each locale variant (`article.en`, `article.fr`) and the
name the route was declared with (`article`).

### Generating the site

```bash
bin/console static-site:generate --env=prod
```

A kernel in debug mode injects the web debug toolbar into every page, so the command refuses
to run there unless you pass `--allow-debug`. Pass `-v` to list each page as it is written; it
exits non-zero if any page failed.

Both commands take repeatable `--route` and `--path` globs, so you can regenerate part of the
site, or preview what would be written without writing it:

```bash
bin/console static-site:generate --env=prod --route='blog_*' --path='/blog/*'
bin/console static-site:list
```

By default each page is written to `storage` as soon as it is generated, so a page that fails
leaves the other pages of the run published. To make a run all-or-nothing, opt into staging:

```yaml
# config/packages/bakslash_static_site.yaml
bakslash_static_site:
    staging: true
```

With staging enabled, pages and the bundle's headers file are first written to a unique local
temporary directory. If URI generation, rendering, writing a staged page, or preparing the
headers fails, the staged files are discarded and the bundle does not publish them to the
configured `storage`. Temporary
files are removed as execution unwinds, including after an exception or stopped iteration. A
forcibly terminated process can leave its temporary directory behind. Cleanup errors are
reported without losing the original failure; after a successful publication they are only
logged, since the generated site is already on the storage. Staged files are private to the
current user (0600/0700 permissions). The temporary filesystem needs enough free space for
the generated files; set `staging_directory` when the system temporary directory is too small
(e.g. a tmpfs):

```yaml
# config/packages/bakslash_static_site.yaml
bakslash_static_site:
    staging: true
    staging_directory: '%kernel.project_dir%/var'
```

Pass `--dry-run` to prepare the whole export and discard it without touching `storage`,
useful to validate a run before publishing it. With `prune`, the dry run also reports how
many files the run would remove, so the first activation never deletes sight unseen.

Once preparation succeeds, files are streamed to `storage` through Flysystem. This works with
local directories and operator services such as S3. Each file is hashed (SHA-256) as it is
written to the staging area, while its content is at hand, and only the files whose hash
differs from the previous manifest are uploaded: the manifest is the single file the bundle
ever reads from the destination. A run without a readable manifest uploads everything. A
storage modified outside the bundle is not detected nor repaired: the comparison trusts the
manifest, not the actual objects. The configured adapter's write options and visibility still
apply.

A full run ends the publication with a manifest at `_static-site/manifest.json` in the
storage: the files of the run with their SHA-256 hashes, the generation time and the run's
identifier. Like the `_headers` control file, it lives under the underscore prefix reserved
for the bundle's own files, away from any route of the site. It is an internal bookkeeping
file, not a public contract: its format may change between versions, and a `version` field
says which one it is. The `prune` option relies on it; move it with the `manifest` option,
or set that option to null to disable the manifest (and what relies on it) entirely. The
manifest is written last, so a failed transfer leaves the previous one in place; filtered
(`--route`/`--path`) and dry runs write none, since they cannot describe the whole site.

The final transfer is **not atomic**, even for a local destination: Flysystem does not offer a
portable directory swap or multi-file transaction. A transfer failure can leave a mix of old
and new files; the command fails and reports that publication may be partial. Concurrent runs
targeting the same storage are not coordinated; with `prune`, a run that notices another run
published while it was transferring refuses to prune (and logs it), so the race becomes a
skipped cleanup instead of the silent deletion of the other run's files. Do not use the
storage as a live deployment while publishing if readers need a consistent snapshot.

By default the command **only writes generated files, and never deletes anything.** Filtered
runs leave files outside the selection untouched, including an existing headers file. A
filtered run therefore leaves the storage in a mixed state: updated pages beside older ones,
and a headers file computed for an earlier full run. Removed or renamed routes leave their
old files behind.

With `staging`, a full run can instead remove the files the previous published run generated
and the new one no longer does, so the storage reflects the last run:

```yaml
# config/packages/bakslash_static_site.yaml
bakslash_static_site:
    staging: true
    prune: true
```

Pruning relies on the previous run's manifest: only the files it lists
are ever deleted, anything else on the storage is left alone. Only a complete, successful
run prunes: filtered, dry and failed runs (including `staging_failure: publish`) never
delete, and a storage without a readable previous manifest is pruned of nothing. A file
that cannot be deleted is reported in the logs without failing the publication; the removed
files are logged too. Without
staging, or for a deployment you fully control, keep publishing the output in a separate
step and let that step prune:

```bash
rsync -a --delete build/ user@host:/var/www/site/
aws s3 sync build/ s3://my-bucket --delete
```

Pages are rendered one at a time, and Symfony's stateful services are reset between them
(`kernel.reset`, e.g. Doctrine's identity map), so make sure your own stateful services
implement `Symfony\Contracts\Service\ResetInterface`. `kernel.terminate` never runs, so work
deferred to it (emails, Messenger dispatch, ...) does not fire during generation.

## Configuration

```yaml
# config/packages/bakslash_static_site.yaml
bakslash_static_site:
    # A local directory (prefer an absolute path), or a Flysystem operator service ("@id").
    storage: '%kernel.project_dir%/build'

    # The absolute URL the site is served from, used for the links baked into the pages
    # (canonical, og:, sitemaps, feeds). May include a base path, e.g.
    # "https://example.com/docs". Defaults to framework.router.default_uri.
    base_uri: '%env(DEFAULT_URI)%'

    # Minify generated HTML and XML pages. Requires sensiolabs/minify-bundle.
    minify: false

    # Write HTML pages as "<path>/index.html" instead of "<path>.html", so any server
    # serves clean URLs without rewrite rules.
    clean_urls: false

    # Prepare the export in a temporary directory and publish it only once every page
    # succeeded: any failure discards the whole run instead of updating the storage
    # page by page.
    staging: false

    # With "staging", what to do when some pages fail: "discard" publishes nothing,
    # "publish" still publishes the pages that succeeded.
    staging_failure: discard

    # Directory under which the temporary export is created before publication. Defaults
    # to the system temporary directory. Only used with "staging" enabled.
    staging_directory: null

    # With "staging", delete the files the previous published run generated and the new
    # one no longer does, based on its manifest. Only a complete, successful run prunes.
    prune: false

    # With "staging", path of the manifest describing the last published run, relative to
    # the storage root. The "prune" option and the upload of changed files rely on it.
    # Set to null to disable it.
    manifest: '_static-site/manifest.json'

    # Write the headers that describe each page (Content-Security-Policy,
    # Strict-Transport-Security, ...) to this file of the output, in the "_headers"
    # format of Cloudflare Pages and Netlify. Disabled when null.
    headers_file: null
```

To write somewhere other than the local filesystem, point `storage` at a Flysystem operator
you configured yourself, for instance with
[`league/flysystem-bundle`](https://github.com/thephpleague/flysystem-bundle):

```yaml
bakslash_static_site:
    storage: '@flysystem.storage.static_site'
```

A local `storage` directory and its subdirectories are created as `0755` (before the umask is
applied), so a web server running as another user can read them. For other modes, redefine the
`bakslash_static_site.filesystem.visibility` service:

```yaml
# config/services.yaml
services:
    bakslash_static_site.filesystem.visibility:
        class: League\Flysystem\UnixVisibility\PortableVisibilityConverter
        arguments:
            $directoryPublic: 0o775
            $defaultForDirectories: public
```

## Serving the generated site

Pages are written as `<path>.<format>`, where the format comes from the route's `_format`, or
from the response `Content-Type`, and falls back to HTML. So `/articles/{slug}` becomes
`articles/<slug>.html` and an XML feed at `/feed` becomes `feed.xml`. A path ending in a slash
gets `index` appended, so `/` becomes `index.html`.

With `clean_urls: true`, HTML pages are written as `<path>/index.html` instead, which every
server resolves out of the box, so the rewrite rules below become unnecessary. Other formats,
and paths that already end in their extension, are written as plain files either way.

A route returning a redirect (3xx) is written as a meta-refresh stub pointing at its
`Location`, so canonical redirects work on any host. Only http(s) and site-relative targets
are written; anything else fails the page, as does any other non-2xx response. Because of the
redirect handling, don't prerender authenticated routes: an unauthenticated generation request
would capture their login redirect.

Every page is served from the site's `base_uri`. A route requiring another host (a fixed
`host`, or a `{placeholder}` in it that resolves elsewhere) or another scheme generates a
URL no written file can answer, so it fails: a route without parameters aborts the run, and
each offending parameter set fails its own page.

For a custom error page, prerender a `/404.html` route returning a 200 response with your
error markup (the server sets the actual status). Giving the route the `.html` extension keeps
it a file rather than a directory under `clean_urls`, which is what the configurations below,
GitHub Pages and Cloudflare Pages all look for.

### Caddy

```caddyfile
example.com {
    root * /var/www/site
    encode zstd gzip
    try_files {path} {path}.html {path}/index.html
    file_server

    # The headers your application sends (see "Security headers" below).
    header {
        Content-Security-Policy "default-src 'self'"
        Strict-Transport-Security "max-age=31536000; includeSubDomains"
        X-Content-Type-Options nosniff
    }

    handle_errors {
        rewrite * /404.html
        file_server
    }
}
```

### nginx

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/site;

    # The headers your application sends (see "Security headers" below). "always" also
    # sends them with error pages.
    add_header Content-Security-Policy "default-src 'self'" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options nosniff always;

    location / {
        try_files $uri $uri.html $uri/index.html =404;
    }

    error_page 404 /404.html;
}
```

### Apache

```apache
# .htaccess at the document root
DirectoryIndex index.html
ErrorDocument 404 /404.html

RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI}.html -f
RewriteRule ^ %{REQUEST_URI}.html [L]
```

### GitHub Pages and Cloudflare Pages

Both already serve `foo.html` at `/foo`, `index.html` for directories and `404.html` for
missing pages, so no configuration is needed. On GitHub Pages, add an empty `.nojekyll` file
so the output is served verbatim:

```yaml
# .github/workflows/deploy.yml (in a job with pages: write + id-token: write)
- run: bin/console static-site:generate --env=prod
- run: touch build/.nojekyll
- uses: actions/upload-pages-artifact@v5
  with:
      path: build
- uses: actions/deploy-pages@v5
```

On Cloudflare Pages, deploy the directory with `npx wrangler pages deploy build`, or point a
Git integration's output directory at it. `_redirects` and `_headers` files placed in the
output are honoured.

### Security headers

Set `headers_file` to write the headers that describe each page (`Content-Security-Policy`,
`Strict-Transport-Security`, `X-Robots-Tag`, `Link`, ...) in the `_headers` format of
Cloudflare Pages and Netlify:

```yaml
bakslash_static_site:
    headers_file: '_headers'
```

```
# Written by static-site:generate. Edit outside these lines.
/*
  Content-Security-Policy: default-src 'self'
  Strict-Transport-Security: max-age=31536000; includeSubDomains
/blog
  X-Robots-Tag: noindex
# End of static-site:generate.
```

The rules are rewritten on each full run, and left untouched by a run filtered with
`--route` or `--path`. Anything written outside the two comment lines is kept.
The shared `/*` rule also covers your assets. Don't repeat its headers in hand-written rules:
Cloudflare Pages joins the values of a header set by several matching rules, and Netlify keeps
only one of them.

A nonce-based CSP is frozen at build time: every visitor gets the nonce of that single render.
Allow inline scripts and styles by their hash (`'sha256-…'`) instead.

## Events

Hook into a run with a normal listener or subscriber. To add files of your own to the export
(a sitemap, a manifest, ...), implement `Dumper\PreparerInterface` instead and tag the service
with `bakslash_static_site.preparer`: with `staging`, the generator calls it after the pages,
before publication, so the files are prepared in the staging area and published with them, and
throwing aborts the run before anything reaches the storage.

| Event | When | Typical use |
| --- | --- | --- |
| `Event\PageRenderedEvent` | after a page renders, before it is written | transform the page with `$event->setPage(...)` |
| `Event\PageDumpedEvent` | after a page is written; with `staging`, written to the temporary export, before publication | collect the generated URIs, e.g. a build manifest |
| `Event\PageFailedEvent` | a page could not be generated | log or notify; covers URI, render and write failures |
| `Event\GenerationFinishedEvent` | once every page has been processed, even when some failed; not on an aborted run | write a sitemap from `$event->uris`, which leaves out failed pages; `$event->partial` tells a run filtered with `--route`/`--path`, `$event->status` whether the pages were published or discarded |

`GenerationFinishedEvent` keeps its existing role: on a successful run it is dispatched after
the prepared files have reached `storage`, so existing sitemap and deployment listeners can
keep using `bakslash_static_site.filesystem`, which remains the configured destination. It is
still dispatched when some pages of a staged run failed: `$event->uris` then lists the prepared
pages with a `Discarded` status, because none of them reached the storage. Check
`$event->isPublished()` before acting on the URIs: the event alone is not proof of success.
Files written by application listeners, or by a custom dumper that bypasses the bundle's
staging filesystem, are outside the protected preparation step. A listener can therefore still
change storage or fail after publication. Writing to the bundle's staging area after the
publication (from a `GenerationFinishedEvent` listener, for instance) is a clear error: the
staged files are deleted as the run unwinds, so such a file would never reach the storage. The
command then fails with a `PostPublicationException` whose message says the pages were published
anyway, so a deployment is never rolled back on the belief that nothing reached the storage.

```php
use Bakslash\StaticSiteBundle\Event\PageRenderedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class RewriteLinks
{
    public function __invoke(PageRenderedEvent $event): void
    {
        $page = $event->getPage();
        // withContent() keeps the format and the response headers of the page.
        $event->setPage($page->withContent(
            str_replace('https://staging.example.com', 'https://example.com', $page->content),
        ));
    }
}
```

## Replacing a moving part

Each step is a service behind an interface. Redefine its id in your application configuration,
which wins over the bundle's definition:

```yaml
# config/services.yaml
services:
    # implements Bakslash\StaticSiteBundle\Dumper\DumperInterface
    bakslash_static_site.dumper:
        class: App\StaticSite\MyDumper
```

| Service id | Interface |
| --- | --- |
| `bakslash_static_site.uri_provider` | `UriProvider\UriProviderInterface` |
| `bakslash_static_site.renderer` | `Renderer\RendererInterface` |
| `bakslash_static_site.dumper` | `Dumper\DumperInterface` |
| `bakslash_static_site.filesystem` | Flysystem's `FilesystemOperator` |
| `bakslash_static_site.filesystem.visibility` | Flysystem's `UnixVisibility\VisibilityConverter` (local `storage` only) |

To fail a single page and let the run continue, throw `Exception\RenderingException` from a
renderer or `Exception\DumpingException` from a dumper, and yield a
`UriProvider\UriGenerationFailure` from a URI provider. Any other exception aborts the run.
Everything the bundle throws implements `Exception\ExceptionInterface`.

Minification runs as a `PageRenderedEvent` listener, so it applies to whatever the renderer
produces, including a custom one. The generator driving these is internal:
`static-site:generate` is the entry point, and the events above are how you hook into a run.

## License

Released under the [MIT License](LICENSE).
