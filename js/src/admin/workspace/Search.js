import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Stream from 'flarum/common/utils/Stream';
import { t, request, quietRequest } from '../util';
import { when, number, link } from './format';

const INSPECT_ERRORS = ['not_connected', 'daily_cap', 'minute_cap', 'paused', 'no_token'];

/** Search Console: connect, then clicks, impressions, CTR and position per page and query. */
export default class Search extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.data = null;
    this.key = Stream('');
    this.property = Stream('');
    this.error = null;
    this.items = [];
    this.total = 0;
    this.sort = 'clicks';
    this.open = {};
    this.inspections = {};
    this.sitemaps = null;
    this.load();
  }

  load() {
    return request('GET', '/search').then((data) => this.setData(data));
  }

  setData(data) {
    this.data = data;
    if (!this.property()) this.property(data.property || `sc-domain:${location.hostname}`);
    if (data.connected && data.propertyValid) this.pages();
    m.redraw();
  }

  pages(more = false) {
    request('GET', '/search/pages', null, { sort: this.sort, offset: more ? this.items.length : 0 }).then((response) => {
      this.items = more ? this.items.concat(response.data) : response.data;
      this.total = response.total;
      m.redraw();
    });
  }

  connect() {
    this.busy = true;
    this.error = null;
    quietRequest('POST', '/search/connect', { key: this.key(), property: this.property() })
      .then((data) => {
        this.busy = false;
        this.key('');
        this.tokenOk = data.tokenOk;
        this.setData(data);
      })
      .catch((error) => {
        this.busy = false;
        this.error = error.response?.errors?.[0]?.detail || t('search.connect_failed');
        m.redraw();
      });
  }

  action(method, path, then) {
    this.busy = true;
    request(method, path).then((data) => {
      this.busy = false;
      then(data);
      m.redraw();
    });
  }

  toggle(page) {
    if (this.open[page.id]) {
      delete this.open[page.id];
      return;
    }
    this.open[page.id] = 'loading';
    request('GET', '/search/queries', null, { path: page.path }).then((data) => {
      this.open[page.id] = data;
      m.redraw();
    });
  }

  inspect(page) {
    this.inspections[page.id] = 'loading';
    request('POST', '/search/inspect', { path: page.path }).then((result) => {
      this.inspections[page.id] = result;
      m.redraw();
    });
  }

  view() {
    const d = this.data;
    if (!d) return <LoadingIndicator />;

    return (
      <div className="SurveyorSection">
        {this.connection(d)}
        {d.connected && d.propertyValid ? this.report(d) : null}
      </div>
    );
  }

  connection(d) {
    return (
      <div className="SurveyorConnect">
        {d.connected ? (
          <p>
            {t('search.connected_as', { email: d.email || '–' })}{' '}
            <Button className="Button Button--link" onclick={() => this.action('POST', '/search/disconnect', (data) => this.setData(data))}>
              {t('search.disconnect')}
            </Button>
          </p>
        ) : (
          <p className="helpText">{t('search.help')}</p>
        )}
        <details open={!d.connected}>
          <summary>{d.connected ? t('search.change') : t('search.connect_heading')}</summary>
          <div className="Form-group">
            <label for="surveyor-gsc-key">{t('search.key')}</label>
            <textarea
              id="surveyor-gsc-key"
              className="FormControl SurveyorMono"
              rows="5"
              autocomplete="off"
              spellcheck="false"
              placeholder={d.connected ? t('search.key_keep') : '{ "type": "service_account", … }'}
              bidi={this.key}
            />
            <p className="helpText">{t('search.key_help')}</p>
          </div>
          <div className="Form-group">
            <label for="surveyor-gsc-property">{t('search.property')}</label>
            <input id="surveyor-gsc-property" className="FormControl SurveyorMono" bidi={this.property} />
            <p className="helpText">{t('search.property_help')}</p>
          </div>
          {this.error ? <p className="SurveyorWarn">{this.error}</p> : null}
          <Button className="Button Button--primary" loading={this.busy} onclick={() => this.connect()}>
            {t('search.connect')}
          </Button>
          <Button className="Button Button--link" onclick={() => this.attrs.go('guide')}>
            {t('search.how_to_key')}
          </Button>
        </details>
        {this.tokenOk === false ? <p className="SurveyorWarn">{t('search.token_failed')}</p> : null}
      </div>
    );
  }

  report(d) {
    const tt = d.totals;
    const change = (now, before) => (before ? Math.round(((now - before) / before) * 100) : null);
    const max = Math.max(1, ...d.days.map((day) => day.clicks));

    return (
      <>
        <div className="SurveyorRun">
          <div className="SurveyorRun-text">
            {d.refreshedAt ? t('search.refreshed', { when: when(d.refreshedAt) }) : t('search.never_refreshed')}
            {d.pausedUntil ? <div className="SurveyorWarn">{t('search.paused', { when: when(d.pausedUntil), error: d.lastError || '' })}</div> : null}
          </div>
          <Button
            className="Button"
            icon="fas fa-sync"
            loading={this.busy}
            onclick={() =>
              this.action('POST', '/search/refresh', (data) => {
                this.refreshError = data.error;
                this.setData(data);
              })
            }
          >
            {t('search.refresh')}
          </Button>
        </div>
        {this.refreshError ? (
          <p className="SurveyorWarn">
            {t('search.refresh_failed', {
              error: INSPECT_ERRORS.includes(this.refreshError) ? t(`search.inspect_${this.refreshError}`) : this.refreshError,
            })}
          </p>
        ) : null}

        <div className="SurveyorTiles">
          {[
            ['clicks', tt.clicks, tt.prevClicks],
            ['impressions', tt.impressions, tt.prevImpressions],
          ].map(([key, now, before]) => (
            <div className="SurveyorTile">
              <div className="SurveyorTile-label">{t(`search.${key}_28`)}</div>
              <div className="SurveyorTile-value">{number(now)}</div>
              {change(now, before) !== null ? (
                <div className={`SurveyorChange ${change(now, before) < 0 ? 'is-down' : 'is-up'}`}>
                  {t('search.vs_before', { change: (change(now, before) > 0 ? '+' : '') + change(now, before) + '%' })}
                </div>
              ) : null}
            </div>
          ))}
          <div className="SurveyorTile">
            <div className="SurveyorTile-label">{t('search.pages_seen')}</div>
            <div className="SurveyorTile-value">{number(tt.pages)}</div>
          </div>
        </div>

        {d.days.length > 1 ? (
          <div className="SurveyorTrend SurveyorTrend--wide" aria-label={t('search.daily')}>
            {d.days.map((day) => (
              <div className="SurveyorTrend-bar" title={`${day.day}: ${day.clicks} / ${day.impressions}`}>
                <span style={{ height: `${(day.clicks / max) * 100}%` }} />
              </div>
            ))}
          </div>
        ) : null}

        <h3>{t('search.sitemaps')}</h3>
        {this.sitemapsView(d)}

        <h3>{t('search.pages')}</h3>
        <div className="SurveyorToolbar">
          {['clicks', 'impressions', 'position', 'change'].map((sort) => (
            <Button
              className={`Button ${this.sort === sort ? 'Button--primary' : ''}`}
              onclick={() => {
                this.sort = sort;
                this.pages();
              }}
            >
              {t(`search.sort_${sort}`)}
            </Button>
          ))}
          <span className="SurveyorMuted">{t('search.inspections', { used: d.inspectionsToday, cap: d.inspectionCap })}</span>
        </div>
        {this.items.length === 0 ? <p className="SurveyorEmpty">{t('search.no_pages')}</p> : null}
        {this.items.length ? (
          <table className="SurveyorTable">
            <thead>
              <tr>
                <th>{t('search.col_page')}</th>
                <th>{t('search.col_clicks')}</th>
                <th>{t('search.col_impressions')}</th>
                <th>{t('search.col_ctr')}</th>
                <th>{t('search.col_position')}</th>
                <th />
              </tr>
            </thead>
            <tbody>{this.items.map((page) => this.row(page))}</tbody>
          </table>
        ) : null}
        {this.items.length < this.total ? (
          <Button className="Button" onclick={() => this.pages(true)}>
            {t('common.more', { count: this.total - this.items.length })}
          </Button>
        ) : null}
      </>
    );
  }

  row(page) {
    const open = this.open[page.id];
    const inspection = this.inspections[page.id] === 'loading' ? 'loading' : this.inspections[page.id]?.data || page.inspection;
    const inspectError = this.inspections[page.id]?.error;

    return [
      <tr>
        <td data-label={t('search.col_page')}>
          {link(page.url, page.path)}
          {inspection && inspection !== 'loading' ? (
            <div className="SurveyorMuted">
              <span className={`SurveyorBadge SurveyorBadge--${inspection.verdict === 'PASS' ? 'ok' : 'broken'}`}>{inspection.verdict || '–'}</span>{' '}
              {inspection.coverage || ''} {inspection.lastCrawlAt ? t('search.crawled', { when: when(inspection.lastCrawlAt) }) : ''}
              {inspection.richVerdict ? ' · ' + t('search.rich', { verdict: inspection.richVerdict }) : ''}
            </div>
          ) : null}
          {inspectError ? (
            <div className="SurveyorWarn">
              {INSPECT_ERRORS.includes(inspectError) ? t(`search.inspect_${inspectError}`, { cap: this.data.inspectionCap }) : inspectError}
            </div>
          ) : null}
        </td>
        <td className="SurveyorTable-num" data-label={t('search.col_clicks')}>
          {number(page.clicks)} <span className="SurveyorMuted">({number(page.prevClicks)})</span>
        </td>
        <td className="SurveyorTable-num" data-label={t('search.col_impressions')}>
          {number(page.impressions)}
        </td>
        <td className="SurveyorTable-num" data-label={t('search.col_ctr')}>
          {page.ctr}%
        </td>
        <td className="SurveyorTable-num" data-label={t('search.col_position')}>
          {page.position ?? '–'}
        </td>
        <td className="SurveyorTable-actions">
          <Button className="Button Button--link" onclick={() => this.toggle(page)}>
            {t('search.queries')}
          </Button>
          <Button className="Button Button--link" loading={inspection === 'loading'} onclick={() => this.inspect(page)}>
            {t('search.inspect')}
          </Button>
        </td>
      </tr>,
      open ? (
        <tr className="SurveyorRow-detail">
          <td colspan="6">
            {open === 'loading' ? (
              <LoadingIndicator size="small" />
            ) : open.data.length ? (
              <table className="SurveyorTable SurveyorTable--inner">
                <tbody>
                  {open.data.map((q) => (
                    <tr>
                      <td>{q.query}</td>
                      <td className="SurveyorTable-num">{number(q.clicks)}</td>
                      <td className="SurveyorTable-num">{number(q.impressions)}</td>
                      <td className="SurveyorTable-num">{q.ctr}%</td>
                      <td className="SurveyorTable-num">{q.position ?? '–'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <p className="SurveyorMuted">{t('search.no_queries')}</p>
            )}
          </td>
        </tr>
      ) : null,
    ];
  }

  sitemapsView(d) {
    if (this.sitemaps === null) {
      return (
        <Button className="Button" onclick={() => this.action('GET', '/search/sitemaps', (data) => (this.sitemaps = data))}>
          {t('search.sitemaps_check')}
        </Button>
      );
    }

    const ours = this.sitemaps.data.find((s) => s.path === d.sitemapUrl);

    return (
      <div>
        {this.sitemaps.error ? <p className="SurveyorWarn">{this.sitemaps.error}</p> : null}
        {ours ? (
          <p>
            {t('search.sitemap_known', {
              submitted: when(ours.lastSubmitted),
              read: when(ours.lastDownloaded),
              errors: ours.errors,
              warnings: ours.warnings,
            })}
          </p>
        ) : (
          <p>{t('search.sitemap_unknown', { url: d.sitemapUrl })}</p>
        )}
        <Button className="Button" loading={this.busy} onclick={() => this.action('POST', '/search/sitemaps', (data) => (this.sitemaps = data))}>
          {t('search.sitemap_submit')}
        </Button>
        <p className="helpText">{t('search.sitemap_submit_help')}</p>
      </div>
    );
  }
}
