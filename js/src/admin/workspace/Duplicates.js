import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { t, request } from '../util';
import { link } from './format';

/** Pages that share one title or one description. */
export default class Duplicates extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.kind = 'title';
    this.load();
  }

  load() {
    this.groups = null;
    request('GET', '/duplicates', null, { kind: this.kind }).then((response) => {
      this.groups = response.data;
      m.redraw();
    });
  }

  view() {
    return (
      <div>
        <div className="SurveyorToolbar">
          {['title', 'description'].map((kind) => (
            <Button className={`Button ${this.kind === kind ? 'Button--primary' : ''}`} onclick={() => ((this.kind = kind), this.load())}>
              {t(`duplicates.${kind}`)}
            </Button>
          ))}
        </div>
        <p className="helpText">{t('duplicates.help')}</p>
        {this.groups === null ? <LoadingIndicator /> : null}
        {this.groups && this.groups.length === 0 ? <p className="SurveyorEmpty">{t('duplicates.empty')}</p> : null}
        {(this.groups || []).map((group) => (
          <div className="SurveyorGroup">
            <div className="SurveyorGroup-head">
              <strong>{group.text || '–'}</strong> <span className="SurveyorMuted">{t('duplicates.count', { count: group.total })}</span>
            </div>
            <ul>
              {group.pages.map((page) => (
                <li>{link(page.url, page.path)}</li>
              ))}
            </ul>
          </div>
        ))}
      </div>
    );
  }
}
