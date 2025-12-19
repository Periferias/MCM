import TomSelect from 'tom-select';
import 'tom-select/dist/css/tom-select.default.min.css';

document.addEventListener('DOMContentLoaded', () => {
  const stateElement = document.getElementById('state');
  const cityElement = document.getElementById('city');

  stateElement.classList.remove('form-select');
  cityElement.classList.remove('form-select');

  const stateSelect = new TomSelect(stateElement, {
    create: false,
    sortField: { field: 'text', direction: 'asc' },
    placeholder: 'Selecione um estado',
    allowEmptyOption: false,
  });

  const citySelect = new TomSelect(cityElement, {
    create: false,
    sortField: { field: 'text', direction: 'asc' },
    placeholder: 'Selecione um município',
    allowEmptyOption: false,
  });

  const fetchData = (url) =>
    fetch(url)
      .then(res => (res.ok ? res.json() : []))
      .catch(() => []);

  const clearCities = () => {
    citySelect.clear(true);
    citySelect.clearOptions();
  };

  // Objeto com todas as cidades listadas por estado conforme sua lista
  const allowedCitiesByStateName = {
    "Rondônia": ["Cacoal", "Ji-Paraná", "Porto Velho"],
    "Acre": ["Cruzeiro do Sul", "Rio Branco"],
    "Amazonas": ["Coari", "Humaitá", "Iranduba", "Itacoatiara", "Manacapuru", "Manaus", "Manicoré", "Maués",
                 "Parintins", "São Gabriel da Cachoeira", "Tabatinga", "Tefé"],
    "Roraima": ["Boa Vista"],
    "Pará": ["Abaetetuba","Acará","Alenquer","Altamira","Ananindeua","Baião","Barcarena","Belém",             "Benevides","Bragança","Breves","Cametá","Canaã dos Carajás","Capanema","Capitão Poço",
             "Castanhal","Dom Eliseu","Igarapé-Miri","Itaituba","Juruti","Marabá","Marituba","Moju",
             "Monte Alegre","Novo Repartimento","Óbidos","Oriximiná","Paragominas","Parauapebas",
             "Portel","Redenção","Rondon do Pará","Santa Izabel do Pará","Santarém","São Félix do Xingu",
             "São Miguel do Guamá","Tailândia","Tomé-Açu","Tucuruí","Vigia","Viseu","Xinguara"],
    "Amapá": ["Macapá", "Santana"],
    "Tocantins": ["Araguaína", "Porto Nacional", "Palmas"],
    "Maranhão": ["Açailândia","Bacabal","Balsas","Barra do Corda","Barreirinhas","Buriticupu","Caxias",
                 "Chapadinha","Codó","Coroatá","Grajaú","Imperatriz","Itapecuru Mirim","Paço do Lumiar",
                 "Pinheiro","Raposa","Santa Inês","Santa Luzia","São José de Ribamar","São Luís","Timon",
                 "Tutóia","Viana"],
    "Piauí": ["Floriano","Parnaíba","Picos","Piripiri","Teresina"],
    "Ceará": ["Acaraú","Aquiraz","Aracati","Barbalha","Beberibe","Boa Viagem","Camocim","Canindé",
              "Cascavel","Caucaia","Crateús","Crato","Fortaleza","Granja","Icó","Iguatu","Itapipoca",
              "Juazeiro do Norte","Maracanaú","Maranguape","Morada Nova","Pacajus","Quixadá",
              "Quixeramobim","Russas","São Gonçalo do Amarante","Sobral","Tauá","Tianguá",
              "Trairi","Viçosa do Ceará"],
    "Rio Grande do Norte": ["Açu","Ceará-Mirim","Macaíba","Mossoró","Natal","São Gonçalo do Amarante"],
    "Paraíba": ["Cajazeiras","Campina Grande","Conde","João Pessoa","Lagoa Seca","Massaranduba",
               "Patos","Puxinanã","Queimadas","Santa Rita","Sapé","Sousa"],
    "Pernambuco": ["Abreu e Lima","Araçoiaba","Araripina","Arcoverde","Belo Jardim","Bezerros","Buíque",
                   "Cabo de Santo Agostinho","Camaragibe","Carpina","Caruaru","Escada","Garanhuns","Goiana",
                   "Gravatá","Ipojuca","Ilha de Itamaracá","Jaboatão dos Guararapes","Limoeiro","Olinda",
                   "Ouricuri","Palmares","Paudalho","Paulista","Pesqueira","Petrolina","Recife","Salgueiro",
                   "Santa Cruz do Capibaribe","Serra Talhada","Surubim","Vitória de Santo Antão"],
    "Alagoas": ["Arapiraca","Delmiro Gouveia","Maceió","Messias","Palmeira dos Índios",
                "Penedo","Rio Largo","União dos Palmares"],
    "Sergipe": ["Divina Pastora","Estância","Lagarto","Maruim","Nossa Senhora do Socorro",
                "Riachuelo","São Cristóvão","Siriri","Tobias Barreto"],
    "Bahia": ["Alagoinhas","Barra","Barreiras","Bom Jesus da Lapa","Brumado","Caetité","Camaçari",
              "Campo Formoso","Casa Nova","Conceição do Coité","Cruz das Almas","Euclides da Cunha",
              "Eunápolis","Feira de Santana","Ilhéus","Ipirá","Itaberaba","Itabuna","Itamaraju",
              "Jacobina","Jequié","Juazeiro","Mata de São João","Paulo Afonso","Porto Seguro",
              "Ribeira do Pombal","Santo Amaro","Santo Antônio de Jesus","Santo Estêvão",
              "São Francisco do Conde","São Sebastião do Passé","Senhor do Bonfim","Serrinha",
              "Simões Filho","Teixeira de Freitas","Valença","Vitória da Conquista"],
    "Minas Gerais": ["Belo Horizonte","Janaúba","Januária","Juiz de Fora","São Francisco",
                     "Teófilo Otoni","Uberlândia"],
    "Espírito Santo": ["Aracruz","Vila Velha"],
    "Rio de Janeiro": ["Campos dos Goytacazes","Duque de Caxias","Itaboraí","Magé","Rio de Janeiro"],
    "São Paulo": ["Bauru","Embu das Artes","Guarujá","Guarulhos","Mogi das Cruzes","Mongaguá","Osasco",
                  "Praia Grande","Ribeirão Preto","Santo André","Santos","São Paulo","São Sebastião",
                  "Suzano","Valinhos"],
    "Paraná": ["Curitiba","Guarapuava","Irati","Ponta Grossa"],
    "Santa Catarina": ["Araquari","Chapecó","Palhoça","São Francisco do Sul"],
    "Rio Grande do Sul": ["Alvorada","Cachoeirinha","Canoas","Capão da Canoa","Capela de Santana","Cruz Alta",
                         "Passo Fundo","Pelotas","Porto Alegre","Santa Maria","Sant'Ana do Livramento",
                         "São Leopoldo","Uruguaiana","Viamão"],
    "Mato Grosso do Sul": ["Campo Grande","Corumbá","Dourados","Naviraí","Ponta Porã"],
    "Mato Grosso": ["Barra do Garças","Cuiabá","Rondonópolis","Sinop","Tangará da Serra","Várzea Grande"],
    "Goiás": ["Aparecida de Goiânia","Caldas Novas","Goiânia"],
    "Distrito Federal": ["Brasília"],
  };

  stateSelect.on('change', async (stateId) => {
    clearCities();
    if (!stateId) return;

    const option = stateElement.querySelector(`option[value="${stateId}"]`);
    const stateName = option ? option.textContent.trim() : null;

    if (!stateName || !(stateName in allowedCitiesByStateName)) return;

    // Busca cidades do backend via API (substitua pela sua URL real!)
    const cities = await fetchData(`/api/states/${encodeURIComponent(stateId)}/cities`);

    const allowedCities = allowedCitiesByStateName[stateName];

    // Filtra cidades autorizadas
    const filteredCities = cities.filter(city => allowedCities.includes(city.name));

    filteredCities.forEach(city => {
      citySelect.addOption({
        value: city.id,
        text: city.name
      });
    });

    citySelect.refreshOptions(false);

  });

  // Se já tiver estado selecionado na página, dispara o carregamento das cidades
  const initialState = stateSelect.getValue();
  if (initialState) {
    stateSelect.fire('change', initialState);
  }
});
