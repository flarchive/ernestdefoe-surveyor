import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';
import Link from 'flarum/common/components/Link';

/**
 * The related-discussions block under a discussion's first post. Only loaded
 * while the block is switched on; the list arrives with the discussion
 * itself, so this makes no request of its own.
 */
app.initializers.add('ernestdefoe-surveyor', () => {
  extend(CommentPost.prototype, 'footerItems', function (items) {
    const post = this.attrs.post;
    if (post.number() !== 1) return;

    const related = post.discussion()?.attribute('surveyorRelated');
    if (!Array.isArray(related) || related.length === 0) return;

    items.add(
      'surveyor-related',
      <nav className="SurveyorRelated" aria-label={app.translator.trans('ernestdefoe-surveyor.forum.related.title', {}, true)}>
        <h3 className="SurveyorRelated-title">{app.translator.trans('ernestdefoe-surveyor.forum.related.title')}</h3>
        <ul>
          {related.map((discussion) => (
            <li>
              <Link href={app.route('discussion', { id: discussion.slug })}>{discussion.title}</Link>
            </li>
          ))}
        </ul>
      </nav>,
      -10
    );
  });
});
