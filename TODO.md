## TTR

Right now,
`getNextQueueEntry()` removes the job from the Redis server completely,
`deleteEntry()` is empty.
So if the script crashes during job processing,
there's no chance to re-queue/bury/re-try the job – it's simply gone.
Other work servers have a dedicated "reserved" state for that,
usually with some short auto-release timeout (TTR).

For a similar effect, we could park the job in the *\_wq\_delayed…* list
using Redis Transactions,
although we need a way to safely delete finished jobs from there again.

