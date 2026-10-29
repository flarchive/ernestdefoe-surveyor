import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Icon from 'flarum/common/components/Icon';
import { t, request } from '../util';

const ICONS = { pass: 'fas fa-check-circle', warning: 'fas fa-exclamation-triangle', info: 'fas fa-info-circle' };

/** Checks that the companions, the sitemap, robots.txt, the scheduler and IndexNow are all in order. */
export default class Diagnostics extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.checks = null;
    this.run();
  }

  run() {
    this.checks = null;
    request('GET', '/diagnostics').then((response) => {
      this.checks = response.data;
      m.redraw();
    });
  }

  view() {
    return (
      <div className="SurveyorSection">
        <div className="SurveyorToolbar">
          <p className="helpText">{t('diagnostics.help')}</p>
          <Button className="Button" icon="fas fa-redo" disabled={this.checks === null} onclick={() => this.run()}>
            {t('diagnostics.run')}
          </Button>
        </div>
        {this.checks === null ? <LoadingIndicator /> : null}
        <ul className="SurveyorChecks">
          {(this.checks || []).map((check) => (
            <li className={`SurveyorCheck SurveyorCheck--${check.level}`}>
              <Icon name={ICONS[check.level]} />
              <div>
                <div className="SurveyorCheck-title">{t(`diagnostics.${check.id}.${check.level}`, check.params)}</div>
                <div className="SurveyorMuted">{t(`diagnostics.${check.id}.${check.level}_help`, check.params)}</div>
              </div>
            </li>
          ))}
        </ul>
      </div>
    );
  }
}
