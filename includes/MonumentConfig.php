<?php
class MonumentConfig {
    public const JSON_FIELDS = [
        'type_oeuvre', 'titre', 'attribution_titre', 'personnage', 'sculpteur',
        'fondeur', 'architecte', 'communes_actuelles', 'protections',
        'bibliographie', 'techniques', 'materiaux', 'sources', 'analogies', 'expositions'
    ];

    // Les dates ont été retirées d'ici
    public const SIMPLE_FIELDS = [
        'id', 'oeuvre_localisee', 'dimensions', 'commune_origine', 'commande_etat',
        'proprietaires_actuels', 'latitude', 'longitude'
    ];

    // Nouvelle constante dédiée aux dates complexes
    public const DATE_FIELDS = [
        'annee_creation', 'date_commande', 'inauguration', 'destruction'
    ];

    public const TEXT_FIELDS = [
        'description', 'projet_definitif', 'inscriptions', 'marques', 'historique', 'notes'
    ];

    public static function getTypes() {
        return [
            'arc', 'balustre', 'banc votif', 'bas-relief', 'boule', 'buste', 'buste colossal',
            'carreau', 'carreau circulaire', 'carreau en creux', 'carreau en relief', 'colonne',
            'croix', 'cenotaphe', 'edicule', 'element d\'architecture', 'ensemble', 'exedre',
            'figurine', 'fontaine', 'fragment', 'gisant', 'groupe', 'groupe equestre', 'haut-relief',
            'maquette', 'masque', 'mausolee', 'menhir', 'medaillon', 'monument', 'obelisque',
            'obelisque tronquee', 'panneau decoratif', 'pilastre', 'plaque', 'plaque decorative',
            'plaque en creux', 'portique', 'pyramide', 'relief', 'retable', 'ronde-bosse',
            'socle trapezoidal', 'statue', 'statue equestre', 'statuette', 'stele', 'terme',
            'tombeau', 'torse', 'tour', 'tete', 'urne', 'vase'
        ];
    }

    public static function getMateriaux() {
        return [
            "Acajou", "Acier inoxydable", "Agathe", "Albâtre", "Aluminium", "Améthyste", "Argent",
            "Béton", "Beurre", "Biscuit", "Bois", "Bois de fer", "Bois durci", "Bronze", "Bronze doré",
            "Caoutchouc", "Calcédoine", "Calcite", "Carton", "Carton-pierre et carton-pâte", "Carton romain",
            "Céramique", "Cire", "Coquille", "Corde", "Cristal", "Cuivre", "Cuivre repoussé", "Doré",
            "Dorure", "Etain", "Faïence", "Faïence fine", "Fer", "Fer forgé", "Fonte", "Fonte de fer",
            "Fonte de fer peinte", "Galvanoplastie", "Granit", "Grès", "Grès (céramique)", "Grès (pierre)",
            "Gypse", "Ivoire", "Jaspe", "Laiton", "Lapis-lazuli", "Lave", "Malachite", "Marbre",
            "Matériau inconnu", "Métal", "Mie de pain", "Miroir", "Mortier", "Neige", "Nacre", "Noyer",
            "Onyx", "Opale", "Or", "Os", "Papier", "Papier mâché", "Paille", "Paraffine", "Perle fine",
            "Perles fines", "Pierre", "Pierre fine", "Pierre ornementale", "Pierre précieuse",
            "Pierre carrière", "Pierre semi-précieuse", "Plastiline", "Plastique", "Platine", "Plomb",
            "Plomb métal", "Plâtre", "Porcelaine", "Porphyre", "Pâte de verre", "Pâte à modeler",
            "Régule", "Résine", "Sable", "Sang", "Savon", "Satin", "Schiste", "Staff", "Terre",
            "Terre crue", "Terre cuite", "Tulle", "Verre", "Zinc"
        ];
    }

    public static function getTechniques() {
        return [
            "Acrylic sculpture", "Armatures", "Bas-relief", "Chryséléphantine", "Dorure",
            "Encaustique", "Fonte à la cire perdue", "Fonte au sable", "Galvanoplastie",
            "Gypsographie", "Holographie", "Lottinoplastie", "Marques de fondeur", "Mise au point",
            "Modelage", "Moulage 1 et 2", "Moulage sur nature", "Mousselinoplastie", "Patine",
            "Photogrammétrie", "Photosculpture", "Polychromie", "Pseudo-céramique", "Relief",
            "Restauration", "Taille", "Taille directe", "Téléiconographie", "Videosculpture"
        ];
    }

    public static function getArchitectes() {
        return [
            "Jean Benoit", "Jean Beraud (1882-1954)", "Jean-Marcel Boudoin (1906-1986)",
            "Georges Bourgouin (1874-1938)", "Gabriel Brun", "Joseph Carré (1870 – 1941)",
            "Louis Charles Emile Cirée (1874-1963)", "Benjamin Chaussemiche (1863 - 1945)",
            "Jean-Baptiste Durand", "René Gaillard (1872 - 1951)", "Jules Godefroy (1863 - 1928)",
            "Hilaire Guinet (1878 - 1938)", "Maurice Martineau (1887-1983)", "Lucien Martineau (1890-1972)",
            "Fernand Milord (1869-????)", "Aimé Octobre (1868-1943)", "Désiré Pachy",
            "Edmond Paulin (1848-1915)", "André Ursault (1894-1971)", "Camille Viée (1846-1911)"
        ];
    }

    public static function getFondeurs() {
        return [
            "André", "Andro", "Arthus Bertrand", "Atelier de moulage des musées nationaux",
            "Atelier de moulage du Musée de Sculpture comparée", "Avoiron & Cie", "Bacqueville, Maison",
            "Barbedienne", "Bingen et Costenoble", "Bingen, Pierre", "Bisceglia frères", "Blot, Eugène",
            "Boulenger, Faïencerie Hippolyte", "Brame, Hector", "Brice, Atelier, à Biot",
            "Bronze artistique de Paris", "Bruno, F.", "Bruyas, A., Faïencerie à Saint-Maurice, Seine",
            "Busato", "Calla", "Camus, Paris", "Carpeaux, Atelier", "Chantilly, Manufacture de",
            "Charpentier", "Christofle et Cie", "Clert, Galerie Iris Clert, Paris", "Colin & Cie, E.",
            "Costenoble", "Coubertin, Fonderie de", "Cresson Edition", "Crozatier, Charles", "Cruet, Paul",
            "Daubrée, Alfred", "Delafontaine", "Delasalle", "Dellafos (?)", "Denière", "Denonvilliers",
            "Dihl et Guérhard, ou du duc d'Angoulême, manufacture de porcelaine", "Ducel & Fils, JJ",
            "Duplan et Salles", "Durenne", "Duval, A.", "Eck et Durand", "Elchinger SA, Céramiques",
            "Etling, Paris", "Fabrication française Paris", "Fel, J.", "Fonderie Coopérative des Artistes",
            "Fraisse-Demey, Paris", "Fulda, René, Paris", "Fumière", "Fumière et Gavignot, Paris",
            "Gasne, Louis", "Gille Jeune", "Giroux, Maison Alph., Paris", "Godard, Amédée", "Godard, E.",
            "Godard, Florentin", "Gonon", "Gonot, Henri", "Goupil & Cie éditeur", "Goutier & Cie",
            "Grandhomme-Andro", "Graux-Marly", "Griffoul et Lorge", "Griffoul, Auguste", "Griffoul, Pierre",
            "Gruet", "Gruet Jeune, Charles-Adolphe", "Gruet Jeune, E., Edmond-Paul", "Gruet aîné, Adolphe",
            "Hohwiller", "Houdebine", "Hébrard, A.A.", "Jaboeuf Rouard, Paris", "Jaboeuf et Bezout",
            "Jaboeuf et Cie, Paris", "Janvier-Berchot (Maison), Paris", "La Gerbe d'Or, Paris",
            "Labroue, E. de ou Delabroue, E.", "Lalique et Cie (René), Wingen-sur-Moder, France",
            "Le Paute, fabricant de pendule", "Leblanc-Barbedienne", "Marnyhac", "Martin, Louis",
            "Monduit", "Monnaie de Paris", "Monneron Frères", "Montagutelli Frères, Paris",
            "Muller, Emile", "Nast, Manufacture de", "Nicot (Etablissements René), Vendeuvre",
            "Niderviller, faïencerie de", "Paillard, Victor", "Patrouilleau, R.", "Peyrol, H.",
            "Poussielgue-Rusand", "Puiforcat, Paris", "Quesnel", "Raffl, Maison", "Richard et Quesnel",
            "Richard frères", "Richard, Louis", "Rouard", "Rouard, Henri", "Rudier", "Rudier, Alexis",
            "Rudier, Eugène", "Rudier, François", "Rudier, Georges", "Schmoll, E.", "Servant", "Siot",
            "Siot-Decauville", "Société des Bronzes de Paris", "Sohet, C., & Cie, Paris", "Soleau, E.",
            "Soyer", "Soyer et Inge", "Susse", "Susse Frères", "Sèvres, Manufacture nationale de",
            "Thinot", "Thiébaut", "Thiébaut frères, Fumière et Gavignot Successeurs",
            "Thomire, Pierre-Philippe", "Union artistique de Vaucouleurs", "Val d'Osne", "Valsuani",
            "Valsuani, A.", "Valsuani, C.", "Varages, Manufacture", "Vincennes, Manufacture de",
            "Vittoz", "de Braux"
        ];
    }

    public static function getDefaultMonument() {
        $monument = ['linked_monuments' => []];
        foreach (self::JSON_FIELDS as $f) $monument[$f] = [''];
        foreach (self::SIMPLE_FIELDS as $f) $monument[$f] = '';
        foreach (self::DATE_FIELDS as $f) $monument[$f] = '';
        foreach (self::TEXT_FIELDS as $f) $monument[$f] = '';
        $monument['id'] = null;
        $monument['oeuvre_localisee'] = 'oui';
        $monument['commande_etat'] = 'oui';
        return $monument;
    }
}
?>