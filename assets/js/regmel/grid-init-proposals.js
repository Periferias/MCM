import {
    trans,
    PREVIOUS,
    NEXT,
    SHOWING,
    RESULTS,
    OF,
    TO,
    TABLE_TYPE_A_KEYWORD,
    TABLE_NO_RECORDS_FOUND,
    TABLE_ERROR
} from "../../translator.js";

document.addEventListener('DOMContentLoaded', () => {
    initializeMainGrid();
    initializeNativeGrids();
});

function initializeMainGrid() {
    const table = document.querySelector('table[data-gridjs-mode="bulk"]');

    if (!table) {
        return;
    }

    const headers = Array.from(table.querySelectorAll('thead th'))
        .map((th) => th.textContent.trim())
        .filter((name) => name);

    const columns = [
        {
            id: 'select',
            name: '',
            sort: false,
            plugin: {
                component: window.gridjs.plugins.selection.RowSelection,
            }
        },
        ...headers.map((name) => ({
            name,
            sort: !['foto', 'imagem', 'acoes', 'ações'].includes(normalizeText(name)),
            formatter: (cell) => gridjs.html(cell),
            hidden: normalizeText(name) === 'id',
        }))
    ];

    const data = extractTableRows(table).map((row) =>
        Array.from(row.querySelectorAll('td')).map((td) => td.innerHTML.trim())
    );

    const wrapper = document.createElement('div');
    const container = buildToolbarContainer();
    const pageLimitLabel = buildPageLimitLabel('gridLimitSelect-main');
    const pageLimitSelect = buildPageLimitSelect('gridLimitSelect-main', 50);
    const gridContainer = document.createElement('div');

    container.appendChild(pageLimitLabel);
    container.appendChild(wrapControl(pageLimitSelect));
    wrapper.appendChild(container);
    wrapper.appendChild(gridContainer);

    table.parentNode.insertBefore(wrapper, table);

    const grid = new gridjs.Grid({
        columns,
        data,
        search: true,
        pagination: buildPaginationConfig(Number(pageLimitSelect.value)),
        className: {
            table: 'table table-striped table-hover',
            th: 'bg-dark text-white',
            td: 'bg-light'
        },
        language: {
            search: { placeholder: trans(TABLE_TYPE_A_KEYWORD) },
            pagination: {
                previous: trans(PREVIOUS),
                next: trans(NEXT),
                showing: trans(SHOWING),
                results: trans(RESULTS),
                of: trans(OF),
                to: trans(TO),
            },
            noRecordsFound: trans(TABLE_NO_RECORDS_FOUND),
            error: trans(TABLE_ERROR),
        },
        plugins: [
            {
                id: 'select',
                component: window.gridjs.plugins.selection.RowSelection,
            }
        ],
    });

    grid.render(gridContainer);
    table.remove();

    container.appendChild(buildBulkStatusLabel());
    container.appendChild(buildBulkStatusControls(grid));

    pageLimitSelect.addEventListener('change', () => {
        grid
            .updateConfig({
                data,
                pagination: buildPaginationConfig(Number(pageLimitSelect.value))
            })
            .forceRender();
    });
}

function initializeNativeGrids() {
    document.querySelectorAll('table[data-native-grid="true"]').forEach((table, index) => {
        const state = {
            table,
            allRows: extractTableRows(table),
            emptyRow: table.querySelector('tbody tr td[colspan]')?.closest('tr') ?? null,
            filteredRows: [],
            searchTerm: '',
            currentPage: 1,
            pageLimit: table.dataset.pagination === 'false' ? -1 : 50,
            paginationEnabled: table.dataset.pagination !== 'false',
        };

        if (state.allRows.length === 0) {
            return;
        }

        const controls = buildNativeGridControls(state, index);
        table.parentNode.insertBefore(controls.wrapper, table);
        controls.wrapper.appendChild(table);

        state.searchInput = controls.searchInput;
        state.pageLimitSelect = controls.pageLimitSelect;
        state.summary = controls.summary;
        state.prevButton = controls.prevButton;
        state.nextButton = controls.nextButton;
        state.pageLabel = controls.pageLabel;

        state.searchInput.addEventListener('input', () => {
            state.searchTerm = normalizeText(state.searchInput.value);
            state.currentPage = 1;
            renderNativeGrid(state);
        });

        if (state.pageLimitSelect) {
            state.pageLimitSelect.addEventListener('change', () => {
                state.pageLimit = Number(state.pageLimitSelect.value);
                state.currentPage = 1;
                renderNativeGrid(state);
            });
        }

        state.prevButton?.addEventListener('click', () => {
            if (state.currentPage > 1) {
                state.currentPage -= 1;
                renderNativeGrid(state);
            }
        });

        state.nextButton?.addEventListener('click', () => {
            const totalPages = getTotalPages(state);
            if (state.currentPage < totalPages) {
                state.currentPage += 1;
                renderNativeGrid(state);
            }
        });

        renderNativeGrid(state);
    });
}

function buildNativeGridControls(state, index) {
    const wrapper = document.createElement('div');
    const toolbar = buildToolbarContainer();
    const searchInput = document.createElement('input');
    const searchWrapper = document.createElement('div');
    const summary = document.createElement('span');
    let pageLimitSelect = null;
    let prevButton = null;
    let nextButton = null;
    let pageLabel = null;

    wrapper.className = 'proposal-native-grid';

    searchWrapper.className = 'col-auto';
    searchInput.type = 'search';
    searchInput.className = 'form-control form-control-sm';
    searchInput.placeholder = trans(TABLE_TYPE_A_KEYWORD);
    searchInput.setAttribute('aria-label', `Busca tabela ${index + 1}`);
    searchWrapper.appendChild(searchInput);
    toolbar.appendChild(searchWrapper);

    if (state.paginationEnabled) {
        pageLimitSelect = buildPageLimitSelect(`nativeGridLimitSelect-${index}`, 50);
        toolbar.appendChild(buildPageLimitLabel(`nativeGridLimitSelect-${index}`));
        toolbar.appendChild(wrapControl(pageLimitSelect));
    }

    summary.className = 'text-muted small';
    toolbar.appendChild(summary);

    if (state.paginationEnabled) {
        const paginationControls = document.createElement('div');
        paginationControls.className = 'd-flex align-items-center gap-2 ms-auto';

        prevButton = document.createElement('button');
        prevButton.type = 'button';
        prevButton.className = 'btn btn-outline-secondary btn-sm';
        prevButton.textContent = trans(PREVIOUS);

        nextButton = document.createElement('button');
        nextButton.type = 'button';
        nextButton.className = 'btn btn-outline-secondary btn-sm';
        nextButton.textContent = trans(NEXT);

        pageLabel = document.createElement('span');
        pageLabel.className = 'small text-muted';

        paginationControls.appendChild(prevButton);
        paginationControls.appendChild(pageLabel);
        paginationControls.appendChild(nextButton);
        toolbar.appendChild(paginationControls);
    }

    wrapper.appendChild(toolbar);

    return { wrapper, searchInput, pageLimitSelect, summary, prevButton, nextButton, pageLabel };
}

function renderNativeGrid(state) {
    state.allRows = extractTableRows(state.table);
    state.filteredRows = state.allRows.filter((row) => {
        if (!state.searchTerm) {
            return true;
        }

        return normalizeText(row.textContent).includes(state.searchTerm);
    });

    state.currentPage = Math.min(state.currentPage, getTotalPages(state));

    const visibleRows = getVisibleRows(state);
    const tbody = state.table.querySelector('tbody');

    state.allRows.forEach((row) => {
        row.style.display = 'none';
    });

    if (state.emptyRow) {
        state.emptyRow.style.display = state.filteredRows.length === 0 ? '' : 'none';
    }

    visibleRows.forEach((row) => {
        row.style.display = '';
        tbody.appendChild(row);
    });

    updateNativeGridSummary(state);
    updateNativeGridPagination(state);
}

function updateNativeGridSummary(state) {
    if (!state.summary) {
        return;
    }

    const total = state.filteredRows.length;

    if (total === 0) {
        state.summary.textContent = trans(TABLE_NO_RECORDS_FOUND);
        return;
    }

    if (!state.paginationEnabled || state.pageLimit === -1) {
        state.summary.textContent = `${trans(SHOWING)} 1 ${trans(TO)} ${total} ${trans(OF)} ${total} ${trans(RESULTS)}`;
        return;
    }

    const start = (state.currentPage - 1) * state.pageLimit + 1;
    const end = Math.min(start + state.pageLimit - 1, total);
    state.summary.textContent = `${trans(SHOWING)} ${start} ${trans(TO)} ${end} ${trans(OF)} ${total} ${trans(RESULTS)}`;
}

function updateNativeGridPagination(state) {
    if (!state.paginationEnabled || !state.prevButton || !state.nextButton || !state.pageLabel) {
        return;
    }

    const totalPages = getTotalPages(state);
    state.currentPage = Math.min(state.currentPage, totalPages);

    state.prevButton.disabled = state.currentPage <= 1;
    state.nextButton.disabled = state.currentPage >= totalPages;
    state.pageLabel.textContent = `Página ${state.currentPage} de ${totalPages}`;
}

function getVisibleRows(state) {
    if (!state.paginationEnabled || state.pageLimit === -1) {
        return state.filteredRows;
    }

    const start = (state.currentPage - 1) * state.pageLimit;
    const end = start + state.pageLimit;
    return state.filteredRows.slice(start, end);
}

function getTotalPages(state) {
    if (!state.paginationEnabled || state.pageLimit === -1) {
        return 1;
    }

    return Math.max(1, Math.ceil(state.filteredRows.length / state.pageLimit));
}

function extractTableRows(table) {
    return Array.from(table.querySelectorAll('tbody tr'))
        .filter((row) => !row.querySelector('td[colspan]'));
}

function buildToolbarContainer() {
    const container = document.createElement('div');
    container.className = 'd-flex flex-wrap align-items-center gap-3 mb-3';
    return container;
}

function buildPageLimitLabel(selectId) {
    const labelWrapper = document.createElement('div');
    const label = document.createElement('label');

    labelWrapper.className = 'col-auto';
    label.className = 'col-form-label';
    label.textContent = 'Itens por página:';
    label.setAttribute('for', selectId);
    labelWrapper.appendChild(label);

    return labelWrapper;
}

function buildPageLimitSelect(selectId, defaultValue) {
    const select = document.createElement('select');

    select.id = selectId;
    select.className = 'form-select form-select-sm w-auto';

    [10, 20, 30, 50, 100, 'all'].forEach((option) => {
        const opt = document.createElement('option');
        opt.value = option === 'all' ? -1 : option;
        opt.textContent = option === 'all' ? 'Mostrar todos' : option;

        if (option === defaultValue) {
            opt.selected = true;
        }

        select.appendChild(opt);
    });

    return select;
}

function wrapControl(control) {
    const wrapper = document.createElement('div');
    wrapper.className = 'col-auto';
    wrapper.appendChild(control);
    return wrapper;
}

function buildPaginationConfig(limit) {
    if (limit === -1) {
        return false;
    }

    return {
        enabled: true,
        limit,
        page: 1,
        resetPageOnUpdate: true
    };
}

function buildBulkStatusLabel() {
    const labelWrapper = document.createElement('div');
    const label = document.createElement('label');

    labelWrapper.className = 'col-auto';
    label.className = 'col-form-label';
    label.textContent = 'Atualizar Status em Massa:';
    label.setAttribute('for', 'gridUpdateStatusSelect');
    labelWrapper.appendChild(label);

    return labelWrapper;
}

function buildBulkStatusControls(grid) {
    const selectWrapper = document.createElement('div');
    const selectStatus = document.createElement('select');
    const bulkUpdateButton = document.createElement('button');
    const status = [
        'all',
        'Enviada',
        'Recebida',
        'Sem Adesão do Município',
        'Anuída',
        'Não Anuída',
        'Selecionada',
        'Não Selecionada',
    ];

    selectWrapper.className = 'col d-flex flex-row gap-3';
    selectStatus.id = 'gridUpdateStatusSelect';
    selectStatus.className = 'form-select form-select-sm w-auto';

    status.forEach((option) => {
        const opt = document.createElement('option');
        opt.value = option === 'all' ? -1 : option;
        opt.textContent = option === 'all' ? 'Selecione o status' : option;
        selectStatus.appendChild(opt);
    });

    bulkUpdateButton.className = 'btn btn-primary btn-sm';
    bulkUpdateButton.textContent = 'Atualizar Status';
    bulkUpdateButton.addEventListener('click', () => {
        bulkUpdateButton.disabled = true;
        bulkUpdateButton.textContent = 'Atualizando...';

        const selectedRows = grid.config.store.state.rowSelection.rowIds;
        const selectedData = grid.config.store.state.data.rows.reduce((acc, row) => {
            if (selectedRows.includes(row.id)) {
                acc.push(row.toArray()[1]);
            }
            return acc;
        }, []);

        if (selectedData.length === 0) {
            alert('Nenhuma proposta selecionada.');
            bulkUpdateButton.disabled = false;
            bulkUpdateButton.textContent = 'Atualizar Status';
            return;
        }

        const statusValue = selectStatus.value;
        if (statusValue === 'all' || !statusValue) {
            alert('Por favor, selecione um status válido.');
            bulkUpdateButton.disabled = false;
            bulkUpdateButton.textContent = 'Atualizar Status';
            return;
        }

        fetch('/painel/admin/propostas/bulk-update-status', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                ids: selectedData,
                status: statusValue,
            }),
        }).then(() => {
            window.location.reload();
        }).catch(() => {
            alert(trans(TABLE_ERROR));
            bulkUpdateButton.disabled = false;
            bulkUpdateButton.textContent = 'Atualizar Status';
        });
    });

    selectWrapper.appendChild(selectStatus);
    selectWrapper.appendChild(bulkUpdateButton);

    return selectWrapper;
}

function normalizeText(value) {
    return String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .trim()
        .toLowerCase();
}
