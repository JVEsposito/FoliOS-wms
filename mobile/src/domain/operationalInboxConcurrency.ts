/** Reads share one request per channel and generation. A mutation invalidates older responses. */
export class OperationalInboxReads {
  private epoch = 0;
  private pending = new Map<string, { epoch: number; promise: Promise<unknown> }>();

  invalidate() { this.epoch += 1; }
  generation() { return this.epoch; }
  isCurrent(epoch: number) { return this.epoch === epoch; }

  async read<T>(channel: string, fetcher: () => Promise<T>): Promise<{ current: boolean; value: T }> {
    const epoch = this.epoch;
    let entry = this.pending.get(channel);
    if (!entry || entry.epoch !== epoch) {
      const created = { epoch, promise: Promise.resolve().then(fetcher) };
      this.pending.set(channel, created);
      void created.promise.finally(() => {
        if (this.pending.get(channel) === created) this.pending.delete(channel);
      }).catch(() => undefined);
      entry = created;
    }
    const value = await entry.promise as T;
    return { current: this.isCurrent(epoch), value };
  }
}

/** The reference changes before React renders, so a double tap cannot send two writes. */
export class OperationalActionGate {
  private owner: symbol | null = null;
  begin(): symbol | null {
    if (this.owner) return null;
    return this.owner = Symbol('operator-action');
  }
  finish(owner: symbol) {
    if (this.owner !== owner) return false;
    this.owner = null;
    return true;
  }
  busy() { return this.owner !== null; }
}
