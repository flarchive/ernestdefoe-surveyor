import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

/**
 * Only this shell is in the admin bundle. The workspace (every section) is a
 * lazy chunk, fetched the first time the page opens.
 */
export default class SurveyorPage extends ExtensionPage {
  oninit(vnode) {
    super.oninit(vnode);
    this.Workspace = null;

    import('./workspace/Workspace').then((module) => {
      this.Workspace = module.default;
      m.redraw();
    });
  }

  sections(vnode) {
    const items = super.sections(vnode);
    const Workspace = this.Workspace;

    items.remove('permissions');
    items.remove('content');
    items.add(
      'surveyor',
      <div className="ExtensionPage-settings SurveyorPage">
        <div className="container">{Workspace ? <Workspace page={this} /> : <LoadingIndicator />}</div>
      </div>,
      20
    );

    return items;
  }
}
