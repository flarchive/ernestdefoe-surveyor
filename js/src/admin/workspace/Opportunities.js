import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { t, request } from '../util';
import { number, score, link } from './format';

/** Where pages could win more clicks, most potential first, with what to do about it. */
export default class Opportunities extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.type = '';
    this.priority = '';
    this.load();
  }

  load() {
    this.data = null;
    request('GET', '/opportunities', null, { type: this.type, priority: this.priority }).then((data) => {
      this.data = data;
      m.redraw();
    });
  }

  view() {
    const d = this.data;
    const go = this.attrs.go;

    return (
      <div className="SurveyorSection">
        <p className="helpText">{t('opportunities.help')}</p>
        <div className="SurveyorToolbar">
          <Select
            value={this.priority}
            options={{
              '': t('opportunities.all_priorities'),
              high: t('opportunities.high'),
              medium: t('opportunities.medium'),
              low: t('opportunities.low'),
            }}
            onchange={(v) => ((this.priority = v), this.load())}
          />
          <Select
            value={this.type}
            options={{
              '': t('opportunities.all_types'),
              low_ctr: t('opportunities.type_low_ctr'),
              striking: t('opportunities.type_striking'),
              declining: t('opportunities.type_declining'),
            }}
            onchange={(v) => ((this.type = v), this.load())}
          />
          {d ? <span className="SurveyorMuted">{t('opportunities.counts', d.counts)}</span> : null}
        </div>

        {d === null ? <LoadingIndicator /> : null}
        {d && d.data.length === 0 ? (
          <div className="SurveyorEmpty">
            <p>{t('opportunities.empty')}</p>
            <Button className="Button" onclick={() => go('search')}>
              {t('sections.search')}
            </Button>
          </div>
        ) : null}

        {(d?.data || []).map((o) => (
          <div className="SurveyorLink">
            <div className="SurveyorLink-head">
              <span className={`SurveyorBadge SurveyorBadge--priority-${o.priority}`}>{t(`opportunities.${o.priority}`)}</span>
              <strong>{t(`opportunities.type_${o.type}`)}</strong>
              {link(o.page.url, o.page.path)}
            </div>
            <div className="SurveyorMuted">
              {t('opportunities.numbers', {
                clicks: number(o.page.clicks),
                impressions: number(o.page.impressions),
                ctr: o.page.ctr,
                position: o.page.position ?? '–',
                potential: number(o.potential),
              })}
            </div>
            <p className="SurveyorAction">{t(`opportunities.do_${o.type}`)}</p>
            {o.auditIssues.length ? (
              <p className="SurveyorMuted">
                {t('opportunities.audit_says')} {score(o.auditScore)} {o.auditIssues.map((code) => t(`issues.${code}`)).join(', ')}
              </p>
            ) : null}
            {o.queries.length ? (
              <ul className="SurveyorQueries">
                {o.queries.map((q) => (
                  <li>
                    <span>{q.query}</span>{' '}
                    <span className="SurveyorMuted">
                      {t('opportunities.query', { impressions: number(q.impressions), position: q.position ?? '–' })}
                    </span>
                  </li>
                ))}
              </ul>
            ) : null}
          </div>
        ))}
      </div>
    );
  }
}
