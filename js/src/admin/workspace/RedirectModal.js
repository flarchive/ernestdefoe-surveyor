import app from 'flarum/admin/app';
import FormModal from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import Stream from 'flarum/common/utils/Stream';
import { t, quietRequest } from '../util';

/** Makes a Detour redirect for an address. Detour checks it exactly as its own form does. */
export default class RedirectModal extends FormModal {
  oninit(vnode) {
    super.oninit(vnode);
    this.source = Stream(this.attrs.source || '');
    this.target = Stream('');
    this.status = Stream('301');
    this.mode = Stream('missing');
  }

  className() {
    return 'SurveyorRedirectModal Modal--small';
  }

  title() {
    return t('redirect.title');
  }

  content() {
    const gone = this.status() === '410';

    return (
      <div className="Modal-body">
        <div className="Form-group">
          <label for="surveyor-source">{t('redirect.source')}</label>
          <input id="surveyor-source" className="FormControl SurveyorMono" required bidi={this.source} />
        </div>
        <div className="Form-group">
          <label>{t('redirect.status')}</label>
          <Select
            value={this.status()}
            onchange={(v) => this.status(v)}
            options={{ 301: t('redirect.status_301'), 302: t('redirect.status_302'), 410: t('redirect.status_410') }}
          />
        </div>
        {gone ? null : (
          <div className="Form-group">
            <label for="surveyor-target">{t('redirect.target')}</label>
            <input id="surveyor-target" className="FormControl SurveyorMono" required bidi={this.target} placeholder="/d/123" />
            <p className="helpText">{t('redirect.target_help')}</p>
          </div>
        )}
        <div className="Form-group">
          <label>{t('redirect.mode')}</label>
          <Select
            value={this.mode()}
            onchange={(v) => this.mode(v)}
            options={{ missing: t('redirect.mode_missing'), always: t('redirect.mode_always') }}
          />
          <p className="helpText">{t(`redirect.mode_${this.mode()}_help`)}</p>
        </div>
        <div className="Form-group">
          <Button type="submit" className="Button Button--primary" loading={this.loading}>
            {t('redirect.save')}
          </Button>
        </div>
      </div>
    );
  }

  onsubmit(e) {
    e.preventDefault();
    this.loading = true;

    quietRequest('POST', '/redirects', { source: this.source(), target: this.target(), status: parseInt(this.status(), 10), mode: this.mode() })
      .then(() => {
        this.hide();
        this.attrs.onsave && this.attrs.onsave();
        app.alerts.show({ type: 'success' }, t('redirect.saved'));
      })
      .catch((error) => {
        this.loading = false;
        this.onerror(error);
      });
  }
}
