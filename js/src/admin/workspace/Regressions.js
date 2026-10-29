import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { t, request } from '../util';
import { number, link } from './format';

/** What got worse: audit scores, search visibility and speed, against their last healthy measurement. */
export default class Regressions extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.data = null;
    request('GET', '/regressions').then((response) => {
      this.data = response.data;
      m.redraw();
    });
  }

  view() {
    if (this.data === null) return <LoadingIndicator />;

    return (
      <div className="SurveyorSection">
        <p className="helpText">{t('regressions.help')}</p>
        {this.data.length === 0 ? <p className="SurveyorEmpty">{t('regressions.empty')}</p> : null}
        {this.data.length ? (
          <table className="SurveyorTable">
            <thead>
              <tr>
                <th>{t('regressions.col_kind')}</th>
                <th>{t('regressions.col_page')}</th>
                <th>{t('regressions.col_before')}</th>
                <th>{t('regressions.col_now')}</th>
              </tr>
            </thead>
            <tbody>
              {this.data.map((r) => (
                <tr>
                  <td data-label={t('regressions.col_kind')}>
                    <span className="SurveyorBadge SurveyorBadge--broken">{t(`regressions.kind_${r.kind}${r.metric ? '_' + r.metric : ''}`)}</span>
                    {r.strategy ? <span className="SurveyorMuted"> {t(`speed.${r.strategy}`)}</span> : null}
                  </td>
                  <td data-label={t('regressions.col_page')}>{link(r.url, r.path)}</td>
                  <td className="SurveyorTable-num" data-label={t('regressions.col_before')}>
                    {r.before === null ? '–' : number(r.before)}
                  </td>
                  <td className="SurveyorTable-num" data-label={t('regressions.col_now')}>
                    {r.now === null ? '–' : number(r.now)}
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
