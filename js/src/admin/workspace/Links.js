import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { t, request } from '../util';
import { when, number, classification, link } from './format';
import RedirectModal from './RedirectModal';

/** Internal links whose destination is not a plain, working page — and the fix, through Detour. */
export default class Links extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.classification = '';
    this.ignored = false;
    this.items = [];
    this.total = 0;
    this.sources = {};
    this.missing = null;
    this.loading = true;
    this.load();
    request('GET', '/missing').then((response) => {
      this.missing = response.data;
      m.redraw();
    });
  }

  load(more = false) {
    this.loading = true;
    const params = { classification: this.classification, ignored: this.ignored ? 1 : 0, offset: more ? this.items.length : 0 };

    return request('GET', '/links', null, params).then((response) => {
      this.items = more ? this.items.concat(response.data) : response.data;
      this.total = response.total;
      this.loading = false;
      m.redraw();
    });
  }

  replace(row) {
    this.items = this.items.map((item) => (item.id === row.id ? { ...item, ...row } : item));
    m.redraw();
  }

  toggleSources(item) {
    if (this.sources[item.id]) {
      delete this.sources[item.id];
      return;
    }
    this.sources[item.id] = 'loading';
    request('GET', `/links/${item.id}/sources`).then((response) => {
      this.sources[item.id] = response.data;
      m.redraw();
    });
  }

  recheck(item) {
    item.rechecking = true;
    request('POST', `/links/${item.id}/recheck`).then((response) => this.replace({ ...response.data, rechecking: false }));
  }

  ignore(item, ignored) {
    request('PATCH', `/links/${item.id}`, { ignored }).then(() => {
      this.items = this.items.filter((i) => i.id !== item.id);
      this.total--;
      m.redraw();
    });
  }

  redirect(source) {
    app.modal.show(RedirectModal, { source, onsave: () => this.load() });
  }

  view() {
    const classes = { '': t('links.all') };
    ['broken', 'redirect', 'restricted', 'challenge', 'failed'].forEach((c) => (classes[c] = t(`classes.${c}`)));

    return (
      <div className="SurveyorSection">
        <p className="helpText">{t('links.help')}</p>
        <div className="SurveyorToolbar">
          <Select
            value={this.classification}
            options={classes}
            onchange={(v) => {
              this.classification = v;
              this.load();
            }}
          />
          <Button
            className="Button"
            icon={this.ignored ? 'fas fa-eye' : 'fas fa-eye-slash'}
            onclick={() => {
              this.ignored = !this.ignored;
              this.load();
            }}
          >
            {this.ignored ? t('links.show_active') : t('links.show_ignored')}
          </Button>
        </div>

        {this.items.length === 0 && !this.loading ? <p className="SurveyorEmpty">{t('links.empty')}</p> : null}

        {this.items.map((item) => (
          <div className="SurveyorLink">
            <div className="SurveyorLink-head">
              {classification(item.classification)}
              <span className="SurveyorMuted">{item.status || (item.error ? t(`page.error_${item.error}`) : '')}</span>
              {link(item.url, item.path)}
              {item.redirected ? <span className="SurveyorBadge SurveyorBadge--ok">{t('links.has_redirect')}</span> : null}
            </div>
            {item.classification === 'redirect' && item.finalUrl ? (
              <div className="SurveyorMuted">
                {t('links.goes_to')} {link(item.finalUrl)}
              </div>
            ) : null}
            <div className="SurveyorMuted">{t(`links.explain_${item.classification}`)}</div>
            <div className="SurveyorLink-actions">
              <Button className="Button Button--link" icon="fas fa-link" onclick={() => this.toggleSources(item)}>
                {t('links.sources', { count: item.sources })}
              </Button>
              <Button className="Button" icon="fas fa-redo" loading={item.rechecking} onclick={() => this.recheck(item)}>
                {t('links.recheck')}
              </Button>
              {['broken', 'redirect'].includes(item.classification) && !item.redirected ? (
                <Button className="Button Button--primary" icon="fas fa-route" onclick={() => this.redirect(item.path)}>
                  {t('links.create_redirect')}
                </Button>
              ) : null}
              <Button className="Button" onclick={() => this.ignore(item, !this.ignored)}>
                {this.ignored ? t('links.restore') : t('links.ignore')}
              </Button>
              <span className="SurveyorMuted">{t('links.checked', { when: when(item.crawledAt) })}</span>
            </div>
            {this.sources[item.id] === 'loading' ? <LoadingIndicator size="small" /> : null}
            {Array.isArray(this.sources[item.id]) ? (
              <ul className="SurveyorLink-sources">
                {this.sources[item.id].map((source) => (
                  <li>
                    {link(source.url, source.title || source.path)} <span className="SurveyorMono SurveyorMuted">{source.path}</span>
                  </li>
                ))}
              </ul>
            ) : null}
          </div>
        ))}

        {this.loading ? <LoadingIndicator /> : null}
        {!this.loading && this.items.length < this.total ? (
          <Button className="Button" onclick={() => this.load(true)}>
            {t('common.more', { count: this.total - this.items.length })}
          </Button>
        ) : null}

        <h3>{t('links.missing_heading')}</h3>
        <p className="helpText">{t('links.missing_help')}</p>
        {this.missing === null ? <LoadingIndicator /> : null}
        {this.missing && this.missing.length === 0 ? <p className="SurveyorEmpty">{t('links.missing_empty')}</p> : null}
        {this.missing && this.missing.length ? (
          <table className="SurveyorTable">
            <thead>
              <tr>
                <th>{t('links.missing_url')}</th>
                <th>{t('links.missing_hits')}</th>
                <th>{t('links.missing_last')}</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {this.missing.map((row) => (
                <tr>
                  <td className="SurveyorMono" data-label={t('links.missing_url')}>
                    {row.url}
                  </td>
                  <td className="SurveyorTable-num" data-label={t('links.missing_hits')}>
                    {number(row.hits)}
                  </td>
                  <td className="SurveyorMuted" data-label={t('links.missing_last')}>
                    {when(row.lastSeenAt)}
                  </td>
                  <td className="SurveyorTable-actions">
                    <Button className="Button Button--link" icon="fas fa-route" onclick={() => this.redirect(row.url)}>
                      {t('links.create_redirect')}
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : null}
      </div>
    );
  }
}
