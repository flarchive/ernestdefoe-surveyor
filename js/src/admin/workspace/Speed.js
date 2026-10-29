import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Stream from 'flarum/common/utils/Stream';
import { t, request } from '../util';
import { when, score, link } from './format';

const ms = (value) => (value === null || value === undefined ? '–' : value >= 1000 ? (value / 1000).toFixed(1) + ' s' : value + ' ms');

/** PageSpeed Insights: Lighthouse lab scores and Core Web Vitals from real Chrome users. */
export default class Speed extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.data = null;
    this.path = Stream('/');
    this.key = Stream('');
    this.load('GET', '');
  }

  load(method, path, body) {
    request(method, `/speed${path}`, body).then((data) => {
      this.data = data;
      m.redraw();
    });
  }

  view() {
    const d = this.data;
    if (!d) return <LoadingIndicator />;

    return (
      <div className="SurveyorSection">
        <p className="helpText">{t('speed.help')}</p>

        <div className="SurveyorToolbar">
          <input className="FormControl SurveyorMono SurveyorToolbar-search" bidi={this.path} placeholder="/d/1" />
          <Button className="Button Button--primary" icon="fas fa-tachometer-alt" onclick={() => this.load('POST', '', { path: this.path() })}>
            {t('speed.measure')}
          </Button>
          <span className="SurveyorMuted">{t('speed.usage', { today: d.today, cap: d.cap })}</span>
        </div>
        {d.pausedUntil ? <p className="SurveyorWarn">{t('speed.paused', { when: when(d.pausedUntil) })}</p> : null}
        {d.queue.length ? (
          <p className="SurveyorMuted">
            {t('speed.queued', { count: d.queue.length })} {d.queue.map((q) => `${q.path} (${t(`speed.${q.strategy}`)})`).join(', ')}
          </p>
        ) : null}

        {d.data.length === 0 ? <p className="SurveyorEmpty">{t('speed.empty')}</p> : null}
        {d.data.length ? (
          <table className="SurveyorTable">
            <thead>
              <tr>
                <th>{t('speed.col_page')}</th>
                <th>{t('speed.col_score')}</th>
                <th>{t('speed.col_lab')}</th>
                <th>{t('speed.col_field')}</th>
                <th>{t('speed.col_when')}</th>
              </tr>
            </thead>
            <tbody>
              {d.data.map((r) => (
                <tr>
                  <td data-label={t('speed.col_page')}>
                    {link(r.url, r.path)} <span className="SurveyorBadge">{t(`speed.${r.strategy}`)}</span>
                    {r.error ? <div className="SurveyorWarn">{r.error}</div> : null}
                  </td>
                  <td data-label={t('speed.col_score')}>{score(r.score)}</td>
                  <td className="SurveyorMuted" data-label={t('speed.col_lab')}>
                    LCP {ms(r.lcp)} · TBT {ms(r.tbt)} · CLS {r.cls ?? '–'}
                  </td>
                  <td className="SurveyorMuted" data-label={t('speed.col_field')}>
                    {r.fieldCategory ? (
                      <>
                        <span className={`SurveyorBadge SurveyorBadge--cwv-${r.fieldCategory.toLowerCase()}`}>
                          {t(`speed.cwv_${r.fieldCategory.toLowerCase()}`)}
                        </span>{' '}
                        LCP {ms(r.fieldLcp)} · INP {ms(r.fieldInp)} · CLS {r.fieldCls ?? '–'} {r.fieldScope === 'origin' ? t('speed.whole_site') : ''}
                      </>
                    ) : (
                      t('speed.no_field')
                    )}
                  </td>
                  <td className="SurveyorMuted" data-label={t('speed.col_when')}>
                    {when(r.measuredAt)}
                    <Button className="Button Button--link" onclick={() => this.load('POST', '', { path: r.path, strategy: r.strategy })}>
                      {t('speed.again')}
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : null}

        <h3>{t('speed.key_heading')}</h3>
        <p className="helpText">{d.hasKey ? t('speed.key_set') : t('speed.key_help')}</p>
        <div className="SurveyorToolbar">
          <input className="FormControl SurveyorMono SurveyorToolbar-search" type="password" autocomplete="off" bidi={this.key} placeholder="AIza…" />
          <Button className="Button" onclick={() => (this.load('POST', '/key', { key: this.key() }), this.key(''))}>
            {this.key() ? t('speed.key_save') : t('speed.key_remove')}
          </Button>
        </div>
        {d.keyRejected ? <p className="SurveyorWarn">{t('speed.key_rejected')}</p> : null}
      </div>
    );
  }
}
