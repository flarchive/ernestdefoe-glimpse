import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';

export default class StoryView extends Model {
  viewedAt = Model.attribute<Date, string>('viewedAt', Model.transformDate);
  reaction = Model.attribute<string | null>('reaction');
  user = Model.hasOne<User>('user');
}
