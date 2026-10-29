import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Stream from 'flarum/common/utils/Stream';
import { t, request } from '../util';
import { when, number, score, classification } from './format';
import PageDetail from './PageDetail';
import Duplicates from './Duplicates';

const ISSUES = [
  'title_missing',
  'title_short',
  'title_long',
  'description_missing',
  'description_short',
  'description_long',
  'canonical_missing',
  'canonical_elsewhere',
  'noindex',
  'h1_missing',
  'h1_multiple',
  'thin_content',
  'images_no_alt',
  'structured_data_missing',
  'structured_data_invalid',
  'slow',
];

/** The audit: run it, watch it, and read every page's result. */
export default class Audit extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.tab = 'pages';
    this.run = null;
    this.q = Stream('');
    this.classification = Stream('');
    this.issue = Stream('');
    this.sort = Stream('');
    this.items = [];
    this.total = 0;
    this.open = null;
    this.loading = true;
    this.stepping = false;

    request('GET', '/audit').then((data) => this.setRun(data.run));
    this.load();
  }

  onremove() {
    clearTimeout(this.timer);
    this.stepping = false;
  }

  setRun(run) {
    this.run = run;
    m.redraw();
  }

  load(more = false) {
    this.loading = true;
    const params = {
      q: this.q(),
      classification: this.classification(),
      issue: this.issue(),
      sort: this.sort(),
      offset: more ? this.items.length : 0,
    };

    return request('GET', '/pages', null, params).then((response) => {
      this.items = more ? this.items.concat(response.data) : response.data;
      this.total = response.total;
      this.loading = false;
      m.redraw();
    });
  }

  start() {
    request('POST', '/audit/start').then((data) => {
      this.setRun(data.run);
      this.step();
    });
  }

  stop() {
    this.stepping = false;
    clearTimeout(this.timer);
    request('POST', '/audit/stop').then((data) => {
      this.setRun(data.run);
      this.load();
    });
  }

  /** Keeps the audit moving while this page is open, at the configured pace. */
  step() {
    this.stepping = true;
    request('POST', '/audit/step').then((data) => {
      this.setRun(data.run);
      if (data.run && data.run.status === 'running' && this.stepping) {
        this.timer = setTimeout(() => this.step(), 10000);
      } else {
        this.stepping = false;
        this.load();
      }
    });
  }

  view() {
    const run = this.run;
    const running = run && run.status === 'running';

    return (
      <div className="SurveyorSection">
        <div className="SurveyorRun">
          {running ? (
            <>
              <div className="SurveyorRun-text">
                {t(`audit.phase_${run.phase}`)} · {t('audit.progress', { done: run.pagesDone, queued: run.pagesQueued })}
              </div>
              <progress max={Math.max(run.pagesQueued, 1)} value={run.pagesDone} />
              {this.stepping ? (
                <span className="helpText">{t('audit.stepping')}</span>
              ) : (
                <Button className="Button" icon="fas fa-forward" onclick={() => this.step()}>
                  {t('audit.continue_here')}
                </Button>
              )}
              <Button className="Button Button--danger" icon="fas fa-stop" onclick={() => this.stop()}>
                {t('audit.stop')}
              </Button>
            </>
          ) : (
            <>
              <div className="SurveyorRun-text">
                {run ? t(`audit.last_${run.status}`, { when: when(run.finishedAt || run.startedAt), pages: run.pagesDone }) : t('audit.never')}
              </div>
              <Button className="Button Button--primary" icon="fas fa-play" onclick={() => this.start()}>
                {t('audit.start')}
              </Button>
            </>
          )}
        </div>
        <p className="helpText">{t('audit.help')}</p>

        <nav className="SurveyorSubTabs">
          {['pages', 'duplicates'].map((name) => (
            <Button className={`Button Button--link ${this.tab === name ? 'active' : ''}`} onclick={() => (this.tab = name)}>
              {t(`audit.view_${name}`)}
            </Button>
          ))}
        </nav>

        {this.tab === 'duplicates' ? <Duplicates /> : this.pages()}
      </div>
    );
  }

  pages() {
    const reload = () => this.load();
    const classes = { '': t('audit.all_classes') };
    ['ok', 'redirect', 'broken', 'restricted', 'challenge', 'failed'].forEach((c) => (classes[c] = t(`classes.${c}`)));
    const issues = { '': t('audit.all_issues') };
    ISSUES.forEach((code) => (issues[code] = t(`issues.${code}`)));

    return (
      <>
        <div className="SurveyorToolbar">
          <input
            className="FormControl SurveyorToolbar-search"
            type="search"
            placeholder={t('audit.search')}
            value={this.q()}
            oninput={(e) => {
              this.q(e.target.value);
              clearTimeout(this.searchTimer);
              this.searchTimer = setTimeout(reload, 300);
            }}
          />
          <Select value={this.classification()} options={classes} onchange={(v) => (this.classification(v), reload())} />
          <Select value={this.issue()} options={issues} onchange={(v) => (this.issue(v), reload())} />
          <Select
            value={this.sort()}
            options={{ '': t('audit.sort_score'), path: t('audit.sort_path'), slow: t('audit.sort_slow'), recent: t('audit.sort_recent') }}
            onchange={(v) => (this.sort(v), reload())}
          />
        </div>

        {this.items.length === 0 && !this.loading ? <p className="SurveyorEmpty">{t('audit.empty')}</p> : null}

        {this.items.length ? (
          <table className="SurveyorTable">
            <thead>
              <tr>
                <th>{t('audit.col_score')}</th>
                <th>{t('audit.col_page')}</th>
                <th>{t('audit.col_status')}</th>
                <th>{t('audit.col_issues')}</th>
                <th>{t('audit.col_checked')}</th>
              </tr>
            </thead>
            <tbody>
              {this.items.map((page) => [
                <tr className="SurveyorRow" onclick={() => (this.open = this.open === page.id ? null : page.id)}>
                  <td data-label={t('audit.col_score')}>{score(page.score)}</td>
                  <td data-label={t('audit.col_page')}>
                    <div className="SurveyorRow-title">{page.title || page.path}</div>
                    <div className="SurveyorMono SurveyorRow-path">{page.path}</div>
                  </td>
                  <td data-label={t('audit.col_status')}>
                    {classification(page.classification)} <span className="SurveyorMuted">{page.status || ''}</span>
                  </td>
                  <td data-label={t('audit.col_issues')}>{page.issues.length ? number(page.issues.length) : '–'}</td>
                  <td data-label={t('audit.col_checked')} className="SurveyorMuted">
                    {when(page.crawledAt)}
                  </td>
                </tr>,
                this.open === page.id ? (
                  <tr className="SurveyorRow-detail">
                    <td colspan="5">
                      <PageDetail id={page.id} />
                    </td>
                  </tr>
                ) : null,
              ])}
            </tbody>
          </table>
        ) : null}

        {this.loading ? <LoadingIndicator /> : null}
        {!this.loading && this.items.length < this.total ? (
          <Button className="Button" onclick={() => this.load(true)}>
            {t('common.more', { count: this.total - this.items.length })}
          </Button>
        ) : null}
      </>
    );
  }
}
