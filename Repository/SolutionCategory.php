<?php

namespace Webkul\UVDesk\SupportCenterBundle\Repository;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\Query;

/**
 * Website
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class SolutionCategory extends EntityRepository
{
    const LIMIT = 1000;

    private $defaultSort = 'a.id';
    private $direction = ['asc', 'desc'];
    private $sorting = ['a.name', 'a.dateAdded', 'a.sortOrder'];
    private $safeFields = ['page', 'limit', 'sort', 'order', 'direction'];
    private $allowedFormFields = ['search', 'name', 'description', 'sorting', 'sortOrder', 'status'];
    private $defaultImage = 'https://s3-ap-southeast-1.amazonaws.com/opencart-hd/website/1/2017/01/02/586a365e5e472.default-icon.png';

    private function validateSorting($sorting)
    {
        return in_array($sorting, $this->sorting) ? $sorting : $this->defaultSort;
    }

    private function validateDirection($direction)
    {
        return in_array($direction, $this->direction) ? $direction : Criteria::DESC;
    }

    private function presetting(&$data)
    {
        $data['sort'] = $_GET['sort'] = $this->validateSorting(isset($data['sort']) ? $data['sort'] : false);
        $data['direction'] = $_GET['direction'] = $this->validateDirection(isset($data['direction']) ? $data['direction'] : false);

        $this->cleanAllData($data);
    }

    private function cleanAllData(&$data)
    {
        if(isset($data['isActive'])){
            $data['status'] = $data['isActive'];
            unset($data['isActive']);
            unset($data['solutionId']);
        }
    }

	public function getAllCategories(\Symfony\Component\HttpFoundation\ParameterBag $obj = null, $container, $allResult = false)
    {
        $json = array();
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('a')->from($this->getEntityName(), 'a');

        $data = $obj ? $obj->all() : [];
        $data = array_reverse($data);

        $categories = [];

        if(isset($data['solutionId'])){
            $qbS = $this->getEntityManager()->createQueryBuilder();
            $qbS->select('a.categoryId')->from('Webkul\UVDesk\SupportCenterBundle\Entity\SolutionCategoryMapping', 'a');
            $qbS->where('a.solutionId = :solutionId');
            $qbS->setParameter('solutionId', $data['solutionId']);

            $categories = $qbS->getQuery()->getResult();
            $categories = $categories ? $categories : [0];
        }

        $this->presetting($data);

        foreach ($data as $key => $value) {
            if(!in_array($key,$this->safeFields) && in_array($key, $this->allowedFormFields)) {
                if($key!='dateUpdated' AND $key!='dateAdded' AND $key!='search') {
                        $qb->Andwhere('a.'.$key.' = :'.$key);
                        $qb->setParameter($key, $value);
                } else {
                    if($key == 'search') {
                        $qb->orwhere('a.name'.' LIKE :name');
                        $qb->setParameter('name', '%'.urldecode($value).'%');
                        $qb->orwhere('a.description'.' LIKE :description');
                        $qb->setParameter('description', '%'.urldecode($value).'%');
                    }
                }
            }
        }

        // $qb->Andwhere('a.companyId'.' = :company');
        // $qb->setParameter('company', $container->get('user.service')->getCurrentCompany()->getId());

        if($categories){
            $qb->Andwhere('a.id IN (:categories)');
            $qb->setParameter('categories', $categories);
        }

        if(!$allResult){
            $paginator  = $container->get('knp_paginator');
            $results = $paginator->paginate(
                $qb,
                isset($data['page']) ? $data['page'] : 1,
                isset($data['limit']) ? $data['limit'] : self::LIMIT,
                array('distinct' => false)
            );
        }else{
            $qb->select($allResult);
            $results = $qb->getQuery()->getResult();
            return $results;
        }

        $newResult = [];
        foreach ($results as $key => $result) {
            $newResult[] = array(
                'id'                   => $result->getId(),
                'name'                 => $result->getName(),
                'description'          => $result->getDescription(),
                'status'               => $result->getStatus(),

                'sorting'              => $result->getSorting(),
                'sortOrder'            => $result->getSortOrder(),

                'dateAdded'            => date_format($result->getDateAdded(),"d-M h:i A"),

                'articleCount'         => $this->getArticlesCountByCategory($result->getId()),

                'solutions'            => ($categories ? [] : $this->getSolutionsByCategory($result->getId())),
            );
        }

        $paginationData = $results->getPaginationData();
        $queryParameters = $results->getParams();

        unset($queryParameters['solution']);

        $paginationData['url'] = '#'.$container->get('uvdesk.service')->buildPaginationQuery($queryParameters);

        $json['results'] = $newResult;
        $json['pagination_data'] = $paginationData;
        return $json;
    }

    public function findCategoryById($filterArray = [])
    {
        $json = array();
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('a')->from($this->getEntityName(), 'a');

        foreach ($filterArray as $key => $value) {
            $qb->Andwhere('a.'.$key.' = :'.$key);
            $qb->setParameter($key, $value);
        }

        return $qb->getQuery()->getOneOrNullResult();
       
    }

    public function getArticlesCountByCategory($categoryId, $status = 1)
    {
        $qbS = $this->createQueryBuilder('a');

        $result = $qbS->select('COUNT(DISTINCT ac.id)')
            ->leftJoin('Webkul\UVDesk\SupportCenterBundle\Entity\ArticleCategory','ac','WITH', 'ac.categoryId = a.id')
            ->leftJoin('Webkul\UVDesk\SupportCenterBundle\Entity\Article','aA','WITH', 'ac.articleId = aA.id')
            ->andwhere('ac.categoryId = :categoryId')
            ->andwhere('aA.status IN (:status)')
            ->setParameters([
                'categoryId' => $categoryId ,
                'status' => $status ,
            ])
            ->getQuery()
            ->getSingleScalarResult();
 
        return $result;
    }

    public function getSolutionsByCategory($categoryId)
    {
        $queryBuilder = $this->createQueryBuilder('a');

        $results = $queryBuilder->select('s.id, s.name')
                 ->leftJoin('Webkul\UVDesk\SupportCenterBundle\Entity\SolutionCategoryMapping','ac','WITH', 'ac.categoryId = a.id')
                 ->leftJoin('Webkul\UVDesk\SupportCenterBundle\Entity\Solutions','s','WITH', 'ac.solutionId = s.id')
                 ->andwhere('ac.categoryId = :categoryId')
                 ->setParameters([
                     'categoryId' => $categoryId
                 ])
                 ->getQuery()
                 ->getResult()
        ;

        return $results;
    }

    public function getArticlesByCategory($categoryId)
    {
        $queryBuilder = $this->createQueryBuilder('sc');

        $results = $queryBuilder->select('a.id, a.name, a.slug')
                 ->leftJoin('Webkul\UVDesk\SupportCenterBundle\Entity\ArticleCategery','ac','WITH', 'ac.categoryId = sc.id')
                 ->leftJoin('Webkul\UVDesk\SupportCenterBundle\Entity\Article','a','WITH', 'ac.id = a.id')
                 ->andwhere('ac.categoryId = :categoryId')
                 ->setParameters([
                     'categoryId' => $categoryId
                 ])
                 ->getQuery()
                 ->getResult()
        ;

        return $results;
    }

    public function removeSolutionsByCategory($categoryId, $solutionId)
    {
        $queryBuilder = $this->createQueryBuilder('ac');
        $queryBuilder->delete('UVDeskSupportCenterBundle:SolutionCategoryMapping','ac')
                 ->andwhere('ac.categoryId = :categoryId')
                 ->andwhere('ac.solutionId IN (:solutionId)')
                 ->setParameters([
                     'categoryId' => $categoryId ,
                     'solutionId' => $solutionId ,
                 ])
                 ->getQuery()
                 ->execute()
        ;
    }

    public function removeEntryByCategory($categoryId)
    {
        $where = is_array($categoryId) ? 'ac.categoryId IN (:categoryId)' : 'ac.categoryId = :categoryId';

        $queryBuilder = $this->createQueryBuilder('ac');
        $queryBuilder->delete('UVDeskSupportCenterBundle:SolutionCategoryMapping','ac')
                 ->andwhere($where)
                 ->setParameters([
                     'categoryId' => $categoryId ,
                 ])
                 ->getQuery()
                 ->execute()
        ;

        $queryBuilder->delete('UVDeskSupportCenterBundle:ArticleCategory','ac')
                 ->andwhere($where)
                 ->setParameters([
                     'categoryId' => $categoryId ,
                 ])
                 ->getQuery()
                 ->execute()
        ;
    }

    public function bulkCategoryStatusUpdate($categoryIds, $status)
    {
        $query = 'UPDATE Webkul\UVDesk\SupportCenterBundle\Entity\SolutionCategory sc SET sc.status = '. (int)$status .' WHERE sc.id IN ('.implode(',', $categoryIds).')';

        $this->getEntityManager()->createQuery($query)->execute();
    }

    public function categorySortingUpdate($id, $sort)
    {
        $query = "UPDATE Webkul\UVDesk\SupportCenterBundle\Entity\SolutionCategory sc SET sc.sortOrder = '". (int)$sort ."' WHERE sc.id = '". (int)$id ."'";

        $this->getEntityManager()->createQuery($query)->execute();
    }
}