function modalProposalDetails(event) {
    console.log('=== INICIANDO modalProposalDetails ===');
    console.log('event:', event);
    
    // Prevenir comportamento padrão do link
    if (event && event.preventDefault) {
        event.preventDefault();
    }
    
    const modal = new bootstrap.Modal('#proposalDetails');

    const data = event.getAttribute('data-proposal');
    console.log('data-proposal raw:', data);
    const proposal = JSON.parse(data);
    console.log('proposal object completo:', proposal);

    const quantityHouses = proposal.quantity_houses;
    const pricePerHouse = proposal.price_per_house;

    document.querySelector('#region-name').innerHTML = proposal.region;
    document.querySelector('#state-name').innerHTML = proposal.state;
    document.querySelector('#city-name').innerHTML = proposal.city_name;
    
    // --- NOVAS LINHAS PARA CEP E ENDEREÇO ---
    document.querySelector('#proposal-zipcode').innerHTML = proposal.zipcode || 'Não informado';
    document.querySelector('#proposal-address').innerHTML = proposal.address || 'Não informado';
    // ----------------------------------------

    document.querySelector('#proposal-name-title').innerHTML = proposal.name;
    document.querySelector('#proposal-name').innerHTML = proposal.name;
    document.querySelector('#project-file').innerHTML = '<a href="/painel/admin/propostas/'+proposal.id+'/projeto" download>Clique aqui para baixar o arquivo do projeto.</a>';
    document.querySelector('#company-name').innerHTML = proposal.company;
    document.querySelector('#created-by').innerHTML = proposal.created_by;
    document.querySelector('#created-at').innerHTML = proposal.created_at;
    document.querySelector('#area-characteristic').innerHTML = proposal.area_option;
    document.querySelector('#area-size').innerHTML = proposal.area_size;
    document.querySelector('#quantity-houses').innerHTML = quantityHouses;
    document.querySelector('#price-per-household').innerHTML = pricePerHouse.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    document.querySelector('#total-price').innerHTML = (quantityHouses * pricePerHouse).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

    // Lógica SNPr
    const snprAffiliation = proposal.snpr_affiliation; 
    const snprAffiliationDetails = proposal.snpr_affiliation_details; 
    
    const snprDetailsContainer = document.querySelector('#snpr-affiliation-details-container');

    document.querySelector('#proposal-snpr-affiliation').innerHTML = snprAffiliation || 'Não'; 
    document.querySelector('#snpr-affiliation-details').innerHTML = snprAffiliationDetails || '-';

    if (snprAffiliation === 'Sim') {
        snprDetailsContainer.style.display = 'block';
    } else {
        snprDetailsContainer.style.display = 'none';
    }

    // Renderizar mapa/arquivo poligonal - FORÇA COMO OBJECT PDF
    console.log('=== VERIFICANDO MAP FILE ===');
    console.log('proposal.map_file:', proposal.map_file);
    console.log('proposal.map_file_type:', proposal.map_file_type);
    
    if (proposal.map_file) {
        console.log('MAP FILE EXISTE, renderizando como PDF object...');
        const mapFileHTML = `
            <object style="min-height: 600px;" data="`+proposal.map_file+`" type="application/pdf" width="100%">
                <p>Caso o documento não esteja visível, <a href="`+proposal.map_file+`">clique aqui para acessar o PDF!</a></p>
            </object>
        `;
        const mapFileDiv = document.querySelector('#map-file');
        console.log('mapFileDiv element:', mapFileDiv);
        mapFileDiv.innerHTML = mapFileHTML;
        console.log('Map file renderizado como PDF object');
        console.log('Map file HTML:', mapFileHTML);
    } else {
        console.log('MAP FILE NÃO EXISTE');
        document.querySelector('#map-file').innerHTML = '<p class="text-muted">Nenhum arquivo de mapa poligonal enviado.</p>';
    }

    // Lógica de Anuência
    const agreementStatus = proposal.agreement_status;
    const proposalStatus = proposal.status;
    const termStatus = proposal.term_status;
    const agreementSection = document.querySelector('#agreement-section');
    const agreementStatusDisplay = document.querySelector('#agreement-status-display');
    const agreementActions = document.querySelector('#agreement-actions');

    // Prazo de envio de anuência: 09/03/2026 às 23:59 BRT (= 10/03/2026 02:59:59 UTC)
    const agreementDeadline = new Date('2026-03-10T02:59:59Z');
    const agreementDeadlineExpired = new Date() > agreementDeadline;
    const prazoEncerradoBadge = '<span class="badge bg-secondary mt-2">Prazo encerrado (09/03/2026 às 23h59)</span>';
    
    // Só exibe a seção de anuência se o termo de adesão estiver aprovado
    if (termStatus === 'approved') {
        agreementSection.style.display = 'block';
        
        // Limpar ações anteriores
        agreementActions.innerHTML = '';
    
        if (agreementStatus === 'submitted') {
            agreementStatusDisplay.innerHTML = '<span class="badge bg-warning text-dark">Aguardando Validação</span>';
        } else if (agreementStatus === 'approved') {
            agreementStatusDisplay.innerHTML = '<span class="badge bg-success">Anuência Aprovada</span>';
            agreementActions.innerHTML = `<a href="/painel/admin/propostas/${proposal.id}/agreement-file" target="_blank" class="btn btn-link btn-sm">Ver Documento</a>`;
        } else if (agreementStatus === 'rejected') {
            agreementStatusDisplay.innerHTML = `
                <span class="badge bg-danger">Anuência Rejeitada</span>
                <small class="d-block text-muted mt-2">Motivo: ${proposal.agreement_reason || ''}</small>
            `;
            // Reenvio sempre permitido (documento já foi submetido antes)
            agreementActions.innerHTML = `
                <button onclick="openUploadAgreementModal('${proposal.id}')" 
                        data-bs-toggle="modal" 
                        data-bs-target="#modalUploadAgreement" 
                        class="btn btn-outline-warning btn-sm mt-2">
                    Reenviar Documento
                </button>
            `;
        } else if (proposalStatus === 'Anuída') {
            agreementStatusDisplay.innerHTML = '<span class="badge bg-success">Anuída</span>';
            if (!agreementStatus) {
                agreementActions.innerHTML = agreementDeadlineExpired ? prazoEncerradoBadge : `
                    <button onclick="openUploadAgreementModal('${proposal.id}')" 
                            data-bs-toggle="modal" 
                            data-bs-target="#modalUploadAgreement" 
                            class="btn btn-outline-primary btn-sm mt-2">
                        Enviar Documento
                    </button>
                `;
            }
        } else if (proposalStatus === 'Aguardando Avaliação da Anuência') {
            agreementStatusDisplay.innerHTML = '<span class="badge bg-warning text-dark">Aguardando Avaliação da Anuência</span>';
            if (proposal.status_reason) {
                agreementStatusDisplay.innerHTML += `<small class="d-block text-muted mt-2">Observação: ${proposal.status_reason}</small>`;
            }
        } else if (proposalStatus === 'Não Anuída') {
            agreementStatusDisplay.innerHTML = '<span class="badge bg-danger">Não Anuída</span>';
            if (proposal.status_reason) {
                agreementStatusDisplay.innerHTML += `<small class="d-block text-muted mt-2">Motivo: ${proposal.status_reason}</small>`;
            }
        } else if (proposalStatus === 'Recebida') {
            agreementStatusDisplay.innerHTML = '<span class="badge bg-secondary">Pendente</span>';
            agreementActions.innerHTML = agreementDeadlineExpired ? prazoEncerradoBadge : `
                <button onclick="openAgreementModal('${proposal.id}')" 
                        data-bs-toggle="modal" 
                        data-bs-target="#modalAgreement" 
                        class="btn btn-success btn-sm mt-2">
                    Anuir Proposta
                </button>
            `;
        } else {
            agreementStatusDisplay.innerHTML = '<span class="badge bg-secondary">-</span>';
        }
    } else {
        agreementSection.style.display = 'none';
    }

    modal.show();
    
    return false;
}