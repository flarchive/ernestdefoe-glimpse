import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';
import type Story from './Story';

export default class StoryReport extends Model {
  reason = Model.attribute<string>('reason');
  detail = Model.attribute<string | null>('detail');
  createdAt = Model.attribute<Date, string>('createdAt', Model.transformDate);
  story = Model.hasOne<Story>('story');
  user = Model.hasOne<User>('user');
}
