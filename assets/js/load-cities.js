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
    "Acre": ["Cruzeiro do Sul","Rio Branco"],
    "Alagoas": ["Arapiraca","Barra de Santo Antônio","Coqueiro Seco","Coruripe","Delmiro Gouveia",
                "Maceió","Marechal Deodoro","Messias","Palmeira dos Índios","Paripueira","Penedo",
                "Rio Largo","Santa Luzia do Norte","São Miguel dos Campos","Satuba","União dos Palmares"],
    "Amapá": ["Macapá","Santana"],
    "Amazonas": ["Coari","Humaitá","Iranduba","Itacoatiara","Manacapuru","Manaus","Manicoré","Maués",
                 "Parintins","São Gabriel da Cachoeira","Tabatinga","Tefé"],
    "Bahia": ["Alagoinhas","Araci","Barra","Barreiras","Bom Jesus da Lapa","Brumado","Caetité","Camaçari",
              "Campo Formoso","Candeias","Casa Nova","Catu","Conceição do Coité","Cruz das Almas","Dias d'Ávila",
              "Euclides da Cunha","Eunápolis","Feira de Santana","Guanambi","Ilhéus","Ipirá","Irecê",
              "Itaberaba","Itabuna","Itamaraju","Itapetinga","Jacobina","Jequié","Juazeiro",
              "Lauro de Freitas","Luís Eduardo Magalhães","Madre de Deus","Mata de São João",
              "Paulo Afonso","Poções","Porto Seguro","Ribeira do Pombal","Salvador","Santo Amaro",
              "Santo Antônio de Jesus","Santo Estêvão","São Francisco do Conde","São Sebastião do Passé",
              "Senhor do Bonfim","Serrinha","Simões Filho","Teixeira de Freitas","Tucano","Valença","Vitória da Conquista"],
    "Ceará": ["Acaraú","Aquiraz","Aracati","Barbalha","Beberibe","Boa Viagem","Brejo Santo","Camocim",
              "Canindé","Cascavel","Caucaia","Crateús","Crato","Eusébio","Fortaleza","Granja","Horizonte",
              "Icó","Iguatu","Itaitinga","Itapipoca","Juazeiro do Norte","Limoeiro do Norte","Maracanaú",
              "Maranguape","Morada Nova","Pacajus","Pacatuba","Quixadá","Quixeramobim","Russas",
              "São Benedito","São Gonçalo do Amarante","Sobral","Tauá","Tianguá","Trairi","Viçosa do Ceará"],
    "Distrito Federal": ["Brasília"],
    "Espírito Santo": ["Aracruz","Cachoeiro de Itapemirim","Cariacica","Colatina","Fundão","Guarapari",
                       "Linhares","Nova Venécia","São Mateus","Serra","Viana","Vila Velha","Vitória"],
    "Goiás": ["Abadia de Goiás","Águas Lindas de Goiás","Anápolis","Aparecida de Goiânia","Aragoiânia",
              "Bonfinópolis","Brazabrantes","Caldas Novas","Caldazinha","Catalão","Cidade Ocidental",
              "Cristalina","Formosa","Goianésia","Goiânia","Goianira","Guapó","Hidrolândia","Inhumas",
              "Itumbiara","Jataí","Luziânia","Mineiros","Morrinhos","Nerópolis","Nova Veneza",
              "Novo Gama","Padre Bernardo","Planaltina","Quirinópolis","Rio Verde","Santo Antônio de Goiás",
              "Santo Antônio do Descoberto","Senador Canedo","Trindade","Valparaíso de Goiás"],
    "Maranhão": ["Açailândia","Bacabal","Balsas","Barra do Corda","Barreirinhas","Buriticupu","Caxias",
                 "Chapadinha","Codó","Coroatá","Davinópolis","Grajaú","Imperatriz","Itapecuru Mirim","Paço do Lumiar",
                 "Pinheiro","Raposa","Santa Inês","Santa Luzia","São José de Ribamar","São Luís","Timon",
                 "Tutóia","Viana"],
    "Mato Grosso": ["Alta Floresta","Barra do Garças","Cáceres","Campo Novo do Parecis","Cuiabá",
                    "Lucas do Rio Verde","Nova Mutum","Pontes e Lacerda","Primavera do Leste",
                    "Rondonópolis","Sinop","Sorriso","Tangará da Serra","Várzea Grande"],
    "Mato Grosso do Sul": ["Campo Grande","Corumbá","Dourados","Naviraí","Nova Andradina","Ponta Porã",
                           "Três Lagoas"],
    "Minas Gerais": ["Alfenas","Araguari","Araxá","Barbacena","Belo Horizonte","Belo Oriente","Betim",
                     "Bom Despacho","Brumadinho","Bugre","Caeté","Campo Belo","Caratinga","Cataguases",
                     "Chácara","Claraval","Confins","Congonhas","Conselheiro Lafaiete","Contagem",
                     "Coronel Fabriciano","Curvelo","Divinópolis","Esmeraldas","Ewbank da Câmara","Extrema",
                     "Formiga","Frutal","Governador Valadares","Guaxupé","Ibirité","Igarapé","Ipaba",
                     "Ipatinga","Itabira","Itabirito","Itajubá","Itaúna","Ituiutaba","Jaguaraçu","Janaúba",
                     "Januária","João Monlevade","Juatuba","Juiz de Fora","Lagoa da Prata","Lagoa Santa",
                     "Lavras","Leopoldina","Manhuaçu","Mariana","Mário Campos","Marliéria","Matias Barbosa",
                     "Montes Claros","Muriaé","Naque","Nova Lima","Nova Serrana","Ouro Preto","Paracatu",
                     "Pará de Minas","Passos","Patos de Minas","Patrocínio","Pedro Leopoldo","Pirapora",
                     "Poços de Caldas","Ponte Nova","Pouso Alegre","Raposos","Ribeirão das Neves","Rio Acima",
                     "Sabará","Santa Luzia","Santana do Paraíso","São Francisco","São João del Rei",
                     "São Joaquim de Bicas","São José da Lapa","São Sebastião do Paraíso","Sarzedo",
                     "Sete Lagoas","Simão Pereira","Teófilo Otoni","Timóteo","Três Corações","Três Pontas",
                     "Ubá","Uberaba","Uberlândia","Unaí","Varginha","Vespasiano","Viçosa"],
    "Pará": ["Abaetetuba","Acará","Alenquer","Altamira","Ananindeua","Baião","Barcarena","Belém",
             "Benevides","Bragança","Breves","Cametá","Canaã dos Carajás","Capanema","Capitão Poço",
             "Castanhal","Dom Eliseu","Igarapé-Miri","Itaituba","Itupiranga","Juruti","Marabá","Marituba","Moju",
             "Monte Alegre","Novo Repartimento","Óbidos","Oriximiná","Paragominas","Parauapebas",
             "Portel","Redenção","Rondon do Pará","Santa Izabel do Pará","Santarém","São Félix do Xingu",
             "São Miguel do Guamá","Tailândia","Tomé-Açu","Tucuruí","Vigia","Viseu","Xinguara"],
    "Paraíba": ["Bayeux","Cabedelo","Cajazeiras","Campina Grande","Conde","Guarabira","João Pessoa",
                "Lagoa Seca","Lucena","Massaranduba","Patos","Puxinanã","Queimadas","Santa Rita",
                "Sapé","Sousa"],
    "Paraná": ["Almirante Tamandaré","Apucarana","Arapongas","Araucária","Balsa Nova","Bocaiúva do Sul",
               "Cambé","Campina Grande do Sul","Campo Largo","Campo Magro","Campo Mourão","Carambeí",
               "Cascavel","Castro","Cianorte","Colombo","Contenda","Curitiba","Fazenda Rio Grande",
               "Floresta","Foz do Iguaçu","Francisco Beltrão","Guarapuava","Ibiporã","Iguaraçu","Irati",
               "Itambé","Itaperuçu","Jataizinho","Londrina","Mandaguaçu","Mandirituba",
               "Marechal Cândido Rondon","Marialva","Maringá","Medianeira","Ourizona","Paiçandu",
               "Palmas","Paranaguá","Paranavaí","Pato Branco","Pinhais","Piraquara","Ponta Grossa",
               "Presidente Castelo Branco","Prudentópolis","Quatro Barras","Rio Branco do Sul","Rolândia",
               "Santa Tereza do Oeste","Santa Terezinha de Itaipu","São José dos Pinhais","Sarandi",
               "Telêmaco Borba","Toledo","Umuarama","União da Vitória"],
    "Pernambuco": ["Abreu e Lima","Araçoiaba","Araripina","Arcoverde","Belo Jardim","Bezerros","Brejo da Madre de Deus","Buíque",
                   "Cabo de Santo Agostinho","Camaragibe","Carpina","Caruaru","Escada","Garanhuns",
                   "Goiana","Gravatá","Igarassu","Ipojuca","Ilha de Itamaracá","Itapissuma",
                   "Jabboatão dos Guararapes","Limoeiro","Moreno","Olinda","Ouricuri","Palmares",
                   "Paudalho","Paulista","Pesqueira","Petrolina","Recife","Salgueiro",
                   "Santa Cruz do Capibaribe","São Bento do Una","São Lourenço da Mata","Serra Talhada","Surubim",
                   "Vitória de Santo Antão"],
    "Piauí": ["Altos","Floriano","Parnaíba","Picos","Piripiri","Teresina"],
    "Rio de Janeiro": ["Angra dos Reis","Araruama","Areal","Armação dos Búzios","Arraial do Cabo","Barra do Piraí",
                       "Barra Mansa","Belford Roxo","Cabo Frio","Cachoeiras de Macacu","Carapebus",
                       "Campos dos Goytacazes","Casimiro de Abreu","Conceição de Macabu","Duque de Caxias",
                       "Guapimirim","Itaboraí","Itaguaí","Itaperuna","Japeri","Macaé","Magé","Mangaratiba",
                       "Maricá","Mesquita","Nilópolis","Niterói","Nova Friburgo","Nova Iguaçu","Paracambi",
                       "Petrópolis","Pinheiral","Queimados","Resende","Rio Bonito","Rio das Ostras",
                       "Rio de Janeiro","São Gonçalo","São João da Barra","São João de Meriti",
                       "São Pedro da Aldeia","Saquarema","Seropédica","Tanguá","Teresópolis","Três Rios",
                       "Valença","Volta Redonda"],
    "Rio Grande do Norte": ["Açu","Caicó","Ceará-Mirim","Extremoz","Macaíba","Mossoró","Natal",
                            "Parnamirim","São Gonçalo do Amarante","São José de Mipibu"],
    "Rio Grande do Sul": ["Alegrete","Alvorada","Araricá","Arroio do Padre","Bagé","Bento Gonçalves",
                          "Cachoeira do Sul","Cachoeirinha","Camaquã","Campo Bom","Canela","Canguçu","Canoas","Capão da Canoa",
                          "Capão do Leão","Capela de Santana","Carazinho","Caxias do Sul","Cruz Alta",
                          "Dois Irmãos","Eldorado do Sul","Erechim","Estância Velha","Esteio","Farroupilha",
                          "Glorinha","Gravataí","Guaíba","Ijuí","Ivoti","Lajeado","Lindolfo Collor",
                          "Montenegro","Morro Reuter","Nova Hartz","Nova Santa Rita","Novo Hamburgo","Parobé",
                          "Passo Fundo","Pelotas","Picada Café","Portão","Porto Alegre","Presidente Lucena",
                          "Rio Grande","Santa Cruz do Sul","Santa Maria","Sant'Ana do Livramento","Santa Rosa",
                          "Santiago","Santo Ângelo","São Borja","São Gabriel","São José do Hortêncio","São Leopoldo",
                          "São Sebastião do Caí","Sapiranga","Sapucaia do Sul","Taquara","Tramandaí",
                          "Uruguaiana","Vacaria","Vale Real","Venâncio Aires","Viamão"],
    "Rondônia": ["Ariquemes","Cacoal","Candeias do Jamari","Jaru","Ji-Paraná","Porto Velho",
                 "Rolim de Moura","Vilhena"],
    "Roraima": ["Boa Vista"],
    "Santa Catarina": ["Águas Mornas","Antônio Carlos","Araquari","Araranguá","Balneário Camboriú",
                       "Barra Velha","Biguaçu","Blumenau","Brusque","Caçador","Camboriú","Canoinhas",
                       "Chapecó","Cocal do Sul","Concórdia","Criciúma","Florianópolis","Forquilhinha",
                       "Gaspar","Governador Celso Ramos","Guaramirim","Içara","Ilhota","Imbituba","Indaial","Itajaí",
                       "Itapema","Jaraguá do Sul","Joinville","Lages","Mafra","Morro da Fumaça",
                       "Morro Grande","Navegantes","Nova Veneza","Palhoça","Paulo Lopes","Penha",
                       "Balneário Piçarras","Rio do Sul","Santo Amaro da Imperatriz","São Bento do Sul",
                       "São Francisco do Sul","São José","São Pedro de Alcântara","Siderópolis","Tijucas",
                       "Treviso","Tubarão","Urussanga","Videira","Xanxerê"],
    "São Paulo": ["Alfredo Marcondes","Alumínio","Álvares Machado","Americana","Américo Brasiliense","Amparo","Andradina",
                  "Anhumas","Araçatuba","Araçoiaba da Serra","Araraquara","Araras","Artur Nogueira",
                  "Arujá","Assis","Atibaia","Avaré","Bady Bassitt","Bálsamo","Barretos","Barueri",
                  "Batatais","Bauru","Bebedouro","Bertioga","Birigui","Biritiba Mirim","Boituva",
                  "Botucatu","Bragança Paulista","Brodowski","Cabreúva","Caçapava","Caiabu","Caieiras",
                  "Cajamar","Campinas","Campo Limpo Paulista","Capivari","Caraguatatuba","Carapicuíba",
                  "Catanduva","Cedral","Charqueada","Cosmópolis","Cotia","Cravinhos","Cristais Paulista",
                  "Cruzeiro","Cubatão","Diadema","Embu das Artes","Embu-Guaçu","Emilianópolis",
                  "Estrela do Norte","Fernandópolis","Ferraz de Vasconcelos","Franca","Francisco Morato",
                  "Franco da Rocha","Gavião Peixoto","Guapiaçu","Guaratinguetá","Guarujá","Guarulhos","Hortolândia",
                  "Ibitinga","Ibiúna","Ilhabela","Indaiatuba","Indiana","Ipiguá","Iracemápolis",
                  "Itanhaém","Itapecerica da Serra","Itapetininga","Itapeva","Itapevi","Itapira",
                  "Itaquaquecetuba","Itatiba","Itirapuã","Itu","Itupeva","Jaboticabal","Jacareí","Jaci",
                  "Jaguariúna","Jales","Jambeiro","Jandira","Jardinópolis","Jarinu","Jaú","Jundiaí","Leme",
                  "Lençóis Paulista","Limeira","Lins","Lorena","Louveira","Mairinque","Mairiporã",
                  "Marília","Matão","Mauá","Mirassol","Mirassolândia","Mococa","Mogi das Cruzes",
                  "Mogi Guaçu","Mogi Mirim","Mongaguá","Monteiro Lobato","Monte Mor","Narandiba",
                  "Neves Paulista","Nova Aliança","Nova Odessa","Olímpia","Osasco","Ourinhos",
                  "Patrocínio Paulista","Paulínia","Penápolis","Peruíbe","Piedade","Pindamonhangaba",
                  "Piracicaba","Pirapora do Bom Jesus","Pirapozinho","Pirassununga","Piratininga","Poá",
                  "Porto Feliz","Porto Ferreira","Praia Grande","Presidente Bernardes","Presidente Prudente",
                  "Redenção da Serra","Regente Feijó","Registro","Restinga","Ribeirão Corrente",
                  "Ribeirão Pires","Ribeirão Preto","Rincão","Rio Claro","Rio das Pedras","Rio Grande da Serra",
                  "Salesópolis","Saltinho","Salto","Salto de Pirapora","Santa Bárbara d'Oeste",
                  "Santa Branca","Santa Cruz da Esperança","Santa Isabel","Santa Lúcia","Santana de Parnaíba",
                  "Santo André","Santo Expedito","Santos","São Bernardo do Campo","São Caetano do Sul",
                  "São Carlos","São João da Boa Vista","São José do Rio Pardo","São José do Rio Preto",
                  "São José dos Campos","São Lourenço da Serra","São Paulo","São Roque","São Sebastião",
                  "São Vicente","Serra Azul","Serrana","Sertãozinho","Sorocaba","Sumaré","Suzano",
                  "Taboão da Serra","Taciba","Taquaritinga","Tarabai","Tatuí","Taubaté","Tremembé",
                  "Tupã","Ubatuba","Uchoa","Valinhos","Vargem Grande Paulista","Várzea Paulista",
                  "Vinhedo","Votorantim","Votuporanga"],
    "Sergipe": ["Aracaju","Barra dos Coqueiros","Carmópolis","Divina Pastora","Estância",
                "General Maynard","Itabaiana","Lagarto","Laranjeiras","Maruim","Nossa Senhora do Socorro",
                "Riachuelo","Rosário do Catete","São Cristóvão","Siriri","Tobias Barreto"],
    "Tocantins": ["Araguaína","Gurupi","Palmas","Paraíso do Tocantins","Porto Nacional"],
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
