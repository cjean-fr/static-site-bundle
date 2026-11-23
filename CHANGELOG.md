# Changelog

## Unreleased

- Add an opt-in `staging` option: prepare generated pages and the headers file in a local
  temporary directory and stream them to the configured Flysystem storage only once every page
  succeeded. A failed preparation discards the staged files; filtered runs preserve files
  outside their selection. Final publication remains a non-atomic sequence of writes and can
  partially update storage if a transfer fails. The default stays unchanged: pages are written
  to the storage as they are generated.
- Keep `GenerationFinishedEvent` after publication on successful staged runs, and keep the
  configured destination available as `bakslash_static_site.filesystem`. Application listeners
  and custom dumpers that write directly to the destination remain outside staged preparation.
- Stage files with private permissions (0600/0700), allow pointing the staging area at another
  directory with the new `staging_directory` option, and only log a cleanup failure once the
  publication has succeeded instead of failing the command. Report a discarded run as
  "prepared, none published" in the `static-site:generate` output.
- Add the `staging_failure: publish` policy: with staging, a run with failed pages can
  still publish the pages that succeeded, instead of discarding the whole run.
- Write a `_static-site.json` manifest (files of the run, SHA-256 hashes, generation time)
  as the last publication step of a full staged run; filtered and dry runs write none.
- Add `--dry-run` to `static-site:generate` (requires `staging`): prepare the whole export
  and discard it without publishing anything.
- `GenerationFinishedEvent` keeps the URIs of the prepared pages even when a staged run fails,
  and carries a new `status` (`GenerationStatus::Published` or `Discarded`) telling whether
  those pages reached the storage, instead of reporting an empty URI list on failure.
- Prepare the headers file through an explicit `PreparerInterface` the generator calls, instead
  of the internal `GenerationStartedEvent`/`GenerationPreparedEvent` pair (both removed): the
  lifecycle of the bundle's own output files no longer transits through the public dispatcher.

## 0.2.0

- Dispatch `GenerationFinishedEvent` once a run completes, carrying the URIs of the written
  pages and whether the run was filtered.
- Create the directories of a local `storage` as `0755` (before the umask) instead of Flysystem's
  default `0700`, so a web server running as another user can read the generated site. Existing
  directories keep their mode: remove them, or run `chmod -R u=rwX,go=rX` on them once (#3).
- Add a `bakslash_static_site.filesystem.visibility` service, to redefine for other modes of a
  local `storage`.
- Report a route that targets another host or scheme than the site's as such, instead of
  failing its pages with an unrelated "No route found" rendering error. Such a route without
  parameters now aborts the run; each offending parameter set fails its own page.
- Run the params provider of a localized route in that route's locale, so it can yield
  per-locale parameters such as translated slugs. Requires the translator to be enabled.
- Match `--route` against the canonical name of a localized route, so `--route=article`
  selects every locale variant of `article`.
- Keep the response headers of a rendered page in `RenderedPage::$headers`, and add
  `RenderedPage::withContent()` (#2).
- Add a `headers_file` option, writing the page headers in the `_headers` format of
  Cloudflare Pages and Netlify (#2).
- Resolve every URI before rendering the first page. The reset between pages put the router
  context back to the application's, so the URIs that followed were generated against its host,
  scheme and base path instead of the site's. A misconfigured route now aborts the run before
  any page is written.

## 0.1.0

Initial release. Requires PHP 8.3 and Symfony 7.4 or 8.x.

- Mark controllers with `#[Prerender]`, on a method or on a class to cover every action it
  holds.
- Generate parameterised routes from a `ParamsProviderInterface` service, or from an inline
  list of parameter sets.
- `static-site:generate` writes the site, `static-site:list` previews it; both accept
  repeatable `--route` and `--path` globs.
- Write to a local directory or to any Flysystem operator service, so S3, FTP and the rest
  need no extra code.
- `base_uri` sets the absolute links baked into the pages, and may include a base path.
- `clean_urls` writes HTML pages as `<path>/index.html`, so any server serves clean URLs
  without rewrite rules.
- Optional HTML and XML minification through `sensiolabs/minify-bundle`.
- Redirects are written as static meta-refresh stubs; any other non-2xx response fails the
  page.
- Hook into a run with `PageRenderedEvent`, `PageDumpedEvent` and `PageFailedEvent`.
- Replace the renderer, dumper, URI provider or filesystem by redefining its service id.
