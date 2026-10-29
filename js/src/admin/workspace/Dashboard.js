import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { t, request } from '../util';
import { when, number, score } from './format';

/** The overview: last audit, scores over time, what needs fixing first. */
export default class Dashboard extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.data = null;
    request('GET', '/summary').then((data) => {
      this.data = data;
      m.redraw();
    });
  }

  view() {
    const d = this.data;
    if (!d) return <LoadingIndicator />;

    const go = this.attrs.go;
    const issues = Object.entries(d.issues)
      .filter(([, count]) => count > 0)
      .sort((a, b) => b[1] - a[1]);
    const linkTotal = Object.values(d.links).reduce((a, b) => a + b, 0);
    const finished = d.runs.filter((run) => run.status === 'done').reverse();

    return (
      <div className="SurveyorSection">
        {d.pages === 0 && !d.run ? (
          <div className="SurveyorEmpty">
            <p>{t('dashboard.empty')}</p>
            <Button className="Button Button--primary" icon="fas fa-play" onclick={() => go('audit')}>
              {t('dashboard.first_audit')}
            </Button>
          </div>
        ) : null}

        <div className="SurveyorTiles">
          <div className="SurveyorTile">
            <div className="SurveyorTile-label">{t('dashboard.average')}</div>
            <div className="SurveyorTile-value">{score(d.average)}</div>
          </div>
          <div className="SurveyorTile">
            <div className="SurveyorTile-label">{t('dashboard.pages')}</div>
            <div className="SurveyorTile-value">{number(d.pages)}</div>
          </div>
          <button className="SurveyorTile SurveyorTile--link" onclick={() => go('links')}>
            <div className="SurveyorTile-label">{t('dashboard.links')}</div>
            <div className="SurveyorTile-value">{number(linkTotal)}</div>
          </button>
          <div className="SurveyorTile">
            <div className="SurveyorTile-label">{t('dashboard.duplicates')}</div>
            <div className="SurveyorTile-value">{number(d.duplicates.title + d.duplicates.description)}</div>
          </div>
          <button className="SurveyorTile SurveyorTile--link" onclick={() => go('indexnow')}>
            <div className="SurveyorTile-label">{t('dashboard.indexnow')}</div>
            <div className="SurveyorTile-value SurveyorTile-value--small">
              {d.indexnow.enabled ? t('dashboard.indexnow_on', { pending: d.indexnow.pending }) : t('dashboard.indexnow_off')}
            </div>
          </button>
          <div className="SurveyorTile">
            <div className="SurveyorTile-label">{t('dashboard.missing')}</div>
            <div className="SurveyorTile-value">{number(d.missing)}</div>
          </div>
          <button className="SurveyorTile SurveyorTile--link" onclick={() => go('regressions')}>
            <div className="SurveyorTile-label">{t('dashboard.regressions')}</div>
            <div className="SurveyorTile-value">{number(d.regressions)}</div>
          </button>
          {d.search ? (
            <>
              <button className="SurveyorTile SurveyorTile--link" onclick={() => go('search')}>
                <div className="SurveyorTile-label">{t('dashboard.search_clicks')}</div>
                <div className="SurveyorTile-value">{number(d.search.clicks)}</div>
              </button>
              <button className="SurveyorTile SurveyorTile--link" onclick={() => go('opportunities')}>
                <div className="SurveyorTile-label">{t('dashboard.opportunities')}</div>
                <div className="SurveyorTile-value">{number(d.search.highOpportunities)}</div>
              </button>
            </>
          ) : (
            <button className="SurveyorTile SurveyorTile--link" onclick={() => go('search')}>
              <div className="SurveyorTile-label">{t('dashboard.search_clicks')}</div>
              <div className="SurveyorTile-value SurveyorTile-value--small">{t('dashboard.search_connect')}</div>
            </button>
          )}
        </div>

        <h3>{t('dashboard.audit_heading')}</h3>
        {d.run ? (
          <p>{t('dashboard.running', { done: d.run.pagesDone, queued: d.run.pagesQueued })}</p>
        ) : d.runs[0] ? (
          <p>{t('dashboard.last_run', { when: when(d.runs[0].finishedAt || d.runs[0].startedAt), pages: d.runs[0].pagesDone })}</p>
        ) : null}
        <p className="helpText">
          {t(`dashboard.frequency_${d.frequency}`)} {d.schedulerTick ? '' : t('dashboard.no_scheduler')}
        </p>

        {finished.length > 1 ? (
          <div className="SurveyorTrend" aria-label={t('dashboard.trend')}>
            {finished.map((run) => (
              <div className="SurveyorTrend-bar" title={`${run.avgScore ?? '–'} · ${new Date(run.startedAt).toLocaleDateString()}`}>
                <span style={{ height: `${run.avgScore || 0}%` }} />
              </div>
            ))}
          </div>
        ) : null}

        {issues.length ? (
          <>
            <h3>{t('dashboard.issues_heading')}</h3>
            <table className="SurveyorTable">
              <tbody>
                {issues.map(([code, count]) => (
                  <tr>
                    <td>{t(`issues.${code}`)}</td>
                    <td className="SurveyorTable-num">{number(count)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </>
        ) : null}
      </div>
    );
  }
}
