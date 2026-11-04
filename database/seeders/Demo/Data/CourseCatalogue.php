<?php

namespace Database\Seeders\Demo\Data;

/**
 * Courses per programme and semester: [code, title, type, credits]. VTU-style 2022 scheme codes.
 */
final class CourseCatalogue
{
    /**
     * @return array<string, array<int, list<array{0: string, 1: string, 2: string, 3: int}>>>
     */
    public static function all(): array
    {
        return [
            'BE-CS' => [
                1 => [['22CS11', 'Engineering Mathematics I', 'theory', 4], ['22CS12', 'Programming in C', 'theory_lab', 4], ['22CS13', 'Basic Electrical Engineering', 'theory', 3]],
                2 => [['22CS21', 'Engineering Mathematics II', 'theory', 4], ['22CS22', 'Python Programming', 'theory_lab', 4], ['22CS23', 'Digital Design', 'theory', 3]],
                3 => [['22CS31', 'Data Structures and Applications', 'theory_lab', 4], ['22CS32', 'Computer Organization', 'theory', 3], ['22CS33', 'Discrete Mathematics', 'theory', 3]],
                4 => [['22CS41', 'Design and Analysis of Algorithms', 'theory_lab', 4], ['22CS42', 'Operating Systems', 'theory', 3], ['22CS43', 'Microcontrollers', 'theory', 3]],
                5 => [['22CS51', 'Database Management Systems', 'theory_lab', 4], ['22CS52', 'Computer Networks', 'theory', 4], ['22CS53', 'Theory of Computation', 'theory', 3]],
                6 => [['22CS61', 'Software Engineering', 'theory', 3], ['22CS62', 'Machine Learning', 'theory_lab', 4], ['22CS63', 'Cloud Computing', 'theory', 3]],
                7 => [['22CS71', 'Big Data Analytics', 'theory', 3], ['22CS72', 'Cryptography and Network Security', 'theory', 3], ['22CS73', 'Major Project Phase I', 'project', 2]],
            ],
            'BE-AIDS' => [
                1 => [['22AD11', 'Calculus and Linear Algebra', 'theory', 4], ['22AD12', 'Problem Solving with Python', 'theory_lab', 4], ['22AD13', 'Engineering Physics', 'theory', 3]],
                2 => [['22AD21', 'Probability and Statistics', 'theory', 4], ['22AD22', 'Object Oriented Programming with Java', 'theory_lab', 4], ['22AD23', 'Elements of Electronics', 'theory', 3]],
                3 => [['22AD31', 'Data Structures using Python', 'theory_lab', 4], ['22AD32', 'Introduction to Artificial Intelligence', 'theory', 3], ['22AD33', 'Linear Algebra for Data Science', 'theory', 3]],
                4 => [['22AD41', 'Algorithms for Intelligent Systems', 'theory_lab', 4], ['22AD42', 'Database Systems', 'theory', 3], ['22AD43', 'Data Visualization', 'theory', 3]],
                5 => [['22AD51', 'Machine Learning', 'theory_lab', 4], ['22AD52', 'Natural Language Processing', 'theory', 3], ['22AD53', 'Big Data Technologies', 'theory', 3]],
                6 => [['22AD61', 'Deep Learning', 'theory_lab', 4], ['22AD62', 'Computer Vision', 'theory', 3], ['22AD63', 'Reinforcement Learning', 'theory', 3]],
                7 => [['22AD71', 'Generative AI', 'theory', 3], ['22AD72', 'MLOps', 'theory_lab', 3], ['22AD73', 'Major Project Phase I', 'project', 2]],
            ],
            'BE-IT' => [
                1 => [['22IT11', 'Engineering Mathematics I', 'theory', 4], ['22IT12', 'Programming Fundamentals', 'theory_lab', 4], ['22IT13', 'Engineering Chemistry', 'theory', 3]],
                2 => [['22IT21', 'Engineering Mathematics II', 'theory', 4], ['22IT22', 'Web Technologies', 'theory_lab', 4], ['22IT23', 'Logic Design', 'theory', 3]],
                3 => [['22IT31', 'Data Structures', 'theory_lab', 4], ['22IT32', 'Computer Architecture', 'theory', 3], ['22IT33', 'Information Theory and Coding', 'theory', 3]],
                4 => [['22IT41', 'Object Oriented Analysis and Design', 'theory', 3], ['22IT42', 'Operating Systems', 'theory_lab', 4], ['22IT43', 'Data Communication', 'theory', 3]],
                5 => [['22IT51', 'Database Management Systems', 'theory_lab', 4], ['22IT52', 'Information Security', 'theory', 3], ['22IT53', 'Software Testing', 'theory', 3]],
                6 => [['22IT61', 'Mobile Application Development', 'theory_lab', 4], ['22IT62', 'Internet of Things', 'theory', 3], ['22IT63', 'DevOps', 'theory', 3]],
                7 => [['22IT71', 'Blockchain Technology', 'theory', 3], ['22IT72', 'Enterprise Architecture', 'theory', 3], ['22IT73', 'Major Project Phase I', 'project', 2]],
            ],
            'MTECH-CSE' => [
                1 => [['22SCS11', 'Advanced Algorithms', 'theory', 4], ['22SCS12', 'Advanced Computer Networks', 'theory', 4], ['22SCS13', 'Research Methodology and IPR', 'theory', 3]],
                2 => [['22SCS21', 'Advances in Machine Learning', 'theory_lab', 4], ['22SCS22', 'Distributed Systems', 'theory', 4], ['22SCS23', 'Mini Project', 'project', 2]],
                3 => [['22SCS31', 'Internet of Things Systems', 'theory', 4], ['22SCS32', 'Internship', 'internship', 6], ['22SCS33', 'Project Phase I', 'project', 3]],
            ],
            'MCA' => [
                1 => [['22MCA11', 'Mathematical Foundations for Computer Applications', 'theory', 4], ['22MCA12', 'Programming with Java', 'theory_lab', 4], ['22MCA13', 'Operating System Concepts', 'theory', 3]],
                2 => [['22MCA21', 'Database Management Systems', 'theory_lab', 4], ['22MCA22', 'Web Programming', 'theory_lab', 4], ['22MCA23', 'Software Engineering', 'theory', 3]],
                3 => [['22MCA31', 'Cloud Computing', 'theory', 4], ['22MCA32', 'Machine Learning', 'theory_lab', 4], ['22MCA33', 'Mobile Application Development', 'theory', 3]],
            ],
            'MBA' => [
                1 => [['22MBA11', 'Management and Organizational Behaviour', 'theory', 4], ['22MBA12', 'Managerial Economics', 'theory', 4], ['22MBA13', 'Accounting for Managers', 'theory', 4]],
                2 => [['22MBA21', 'Marketing Management', 'theory', 4], ['22MBA22', 'Financial Management', 'theory', 4], ['22MBA23', 'Human Resource Management', 'theory', 4]],
                3 => [['22MBA31', 'Strategic Management', 'theory', 4], ['22MBA32', 'Business Analytics', 'theory', 4], ['22MBA33', 'Entrepreneurship Development', 'theory', 3]],
            ],
        ];
    }
}
