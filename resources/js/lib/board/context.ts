import type { Board } from '@/composables/useBoard';
import { inject, provide, type InjectionKey } from 'vue';

const KEY: InjectionKey<Board> = Symbol('live-board');

export function provideBoard(board: Board): void {
    provide(KEY, board);
}

/** The live board's state for the components of the room. */
export function useBoardContext(): Board {
    const board = inject(KEY, null);
    if (board === null) throw new Error('The board components must be used inside the board page.');

    return board;
}
