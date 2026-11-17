import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';

export default class StoryMute extends Model {
  mutedUser = Model.hasOne<User>('mutedUser');
}
