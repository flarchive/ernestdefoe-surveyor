import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { t } from '../util';

/**
 * Each section is its own lazy chunk, fetched the first time it is opened,
 * so opening the dashboard does not download the AI or PageSpeed screens.
 */
const SECTIONS = {
  dashboard: () => import('./Dashboard'),
  audit: () => import('./Audit'),
  search: () => import('./Search'),
  opportunities: () => import('./Opportunities'),
  speed: () => import('./Speed'),
  regressions: () => import('./Regressions'),
  internal: () => import('./InternalLinks'),
  links: () => import('./Links'),
  indexnow: () => import('./IndexNow'),
  ai: () => import('./Ai'),
  rules: () => import('./Rules'),
  diagnostics: () => import('./Diagnostics'),
  guide: () => import('./Guide'),
  settings: null,
};

export default class Workspace extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.views = {};
    this.go('dashboard');
  }

  go(section) {
    this.section = section;
    const load = SECTIONS[section];

    if (load && !this.views[section]) {
      load().then((module) => {
        this.views[section] = module.default;
        m.redraw();
      });
    }
  }

  view() {
    const View = this.views[this.section];
    const go = (section) => this.go(section);

    return (
      <div className="Surveyor">
        <nav key="tabs" className="SurveyorTabs" role="tablist">
          {Object.keys(SECTIONS).map((section) => (
            <Button
              className={`Button ${this.section === section ? 'Button--primary' : ''}`}
              role="tab"
              aria-selected={this.section === section ? 'true' : 'false'}
              onclick={() => go(section)}
            >
              {t(`sections.${section}`)}
            </Button>
          ))}
        </nav>
        {/* Keyed, so each section starts fresh when it is opened again. */}
        {this.section === 'settings' ? (
          <div key="settings">{this.attrs.page.content()}</div>
        ) : View ? (
          <View key={this.section} go={go} />
        ) : (
          <div key="loading">
            <LoadingIndicator />
          </div>
        )}
      </div>
    );
  }
}
